<?php
// app/Console/Commands/S3ConfigCommand.php

namespace App\Console\Commands;

use Aws\S3\Exception\S3Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Настраивает S3/MinIO: создаёт бакеты, ставит политики,
 * делает smoke-тест записи/чтения/удаления.
 *
 *  php artisan s3:config
 *  php artisan s3:config --bucket=public
 *  php artisan s3:config --force --skip-test
 */
class S3ConfigCommand extends Command
{
    protected $signature = 's3:config
        {--bucket= : Только указанный бакет (public|private)}
        {--force : Перезаписать политику, даже если она уже стоит}
        {--skip-test : Не выполнять тестовую запись/чтение}';

    protected $description = 'Настраивает S3/MinIO бакеты (публичный и приватный): создаёт при необходимости и ставит политики';

    public function handle(): int
    {
        $endpoint = env('AWS_ENDPOINT');
        $region   = env('AWS_DEFAULT_REGION');

        $this->info('Конфигурация S3:');
        $this->line('  Endpoint: ' . ($endpoint ?: '(AWS)'));
        $this->line('  Region:   ' . ($region ?: '—'));
        $this->newLine();

        $targets = [
            'public' => [
                'disk'   => 's3_public',
                'bucket' => (string) env('AWS_BUCKET_PUBLIC'),
            ],
            'private' => [
                'disk'   => 's3_private',
                'bucket' => (string) env('AWS_BUCKET_PRIVATE'),
            ],
        ];

        $only = $this->option('bucket');
        if ($only !== null && !in_array($only, ['public', 'private'], true)) {
            $this->error('Опция --bucket принимает значения: public или private');
            return self::FAILURE;
        }

        $exit = self::SUCCESS;

        foreach ($targets as $kind => $cfg) {
            if ($only !== null && $only !== $kind) {
                continue;
            }

            if ($cfg['bucket'] === '') {
                $this->error("[{$kind}] В .env не задан AWS_BUCKET_" . strtoupper($kind));
                $exit = self::FAILURE;
                continue;
            }

            if (!config("filesystems.disks.{$cfg['disk']}")) {
                $this->error("[{$kind}] Диск {$cfg['disk']} не найден в config/filesystems.php");
                $exit = self::FAILURE;
                continue;
            }

            try {
                $this->setupBucket($kind, $cfg['disk'], $cfg['bucket']);
            } catch (S3Exception $e) {
                $msg = $e->getAwsErrorMessage() ?: $e->getMessage();
                $this->error("[{$kind}] " . $msg);
                if ($this->output->isVerbose()) {
                    $this->line($e->getTraceAsString());
                }
                $exit = self::FAILURE;
                continue;
            }

            if (!$this->option('skip-test')) {
                try {
                    $this->testBucket($kind, $cfg['disk']);
                } catch (\Throwable $e) {
                    $this->error("[{$kind}] тест не прошёл: " . $e->getMessage());
                    $exit = self::FAILURE;
                }
            }
        }

        return $exit;
    }

    protected function setupBucket(string $kind, string $disk, string $bucket): void
    {
        $this->info("→ [{$kind}] {$bucket}");

        /** @var \Aws\S3\S3Client $client */
        $client = Storage::disk($disk)->getClient();

        $exists = $this->bucketExists($client, $bucket);

        if (!$exists) {
            $params = ['Bucket' => $bucket];

            $region = config("filesystems.disks.{$disk}.region");
            if ($region && $region !== 'us-east-1') {
                $params['CreateBucketConfiguration'] = ['LocationConstraint' => $region];
            }

            $this->line('  Создаём бакет…');
            $client->createBucket($params);
            $client->waitUntil('BucketExists', ['Bucket' => $bucket]);
            $this->line('  ✔ Создан');
        } else {
            $this->line('  ✔ Уже существует');
        }

        if ($kind === 'public') {
            $this->applyPublicPolicy($client, $bucket, (bool) $this->option('force'));
        } else {
            $this->applyPrivatePolicy($client, $bucket);
        }

        $this->newLine();
    }

    protected function bucketExists(\Aws\S3\S3Client $client, string $bucket): bool
    {
        try {
            return $client->doesBucketExist($bucket);
        } catch (S3Exception $e) {
            // Некоторые S3-совместимые API отдают 403 вместо false,
            // если HeadBucket недоступен, но бакет есть.
            if ($e->getStatusCode() === 403) {
                return true;
            }
            throw $e;
        }
    }

    protected function applyPublicPolicy(\Aws\S3\S3Client $client, string $bucket, bool $force): void
    {
        $desired = $this->publicReadPolicy($bucket);
        $desiredJson = json_encode($desired, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (!$force) {
            try {
                $current = $client->getBucketPolicy(['Bucket' => $bucket]);
                $currentJson = (string) $current->get('Policy');
                if ($currentJson === $desiredJson) {
                    $this->line('  ✔ Публичная политика уже на месте');
                    return;
                }
            } catch (S3Exception $e) {
                // NoSuchBucketPolicy — политики нет, ставим
                if (!in_array($e->getAwsErrorCode(), ['NoSuchBucketPolicy', 'NoSuchBucket'], true)) {
                    throw $e;
                }
            }
        }

        $client->putBucketPolicy([
            'Bucket' => $bucket,
            'Policy' => $desiredJson,
        ]);
        $this->line('  ✔ Публичная политика (s3:GetObject для *) установлена');
    }

    protected function applyPrivatePolicy(\Aws\S3\S3Client $client, string $bucket): void
    {
        // Приватный бакет: убираем любую публичную политику.
        // Default в S3 — запрет на анонимный доступ, поэтому этого достаточно.
        try {
            $client->deleteBucketPolicy(['Bucket' => $bucket]);
            $this->line('  ✔ Публичная политика удалена (бакет приватный)');
        } catch (S3Exception $e) {
            $code = $e->getAwsErrorCode();
            if (in_array($code, ['NoSuchBucketPolicy', 'NoSuchBucket'], true) || $e->getStatusCode() === 404) {
                $this->line('  ✔ Публичной политики нет (бакет приватный)');
                return;
            }
            throw $e;
        }
    }

    protected function testBucket(string $kind, string $disk): void
    {
        $fs   = Storage::disk($disk);
        $path = '_probe/s3-config-' . now()->format('YmdHis') . '.txt';
        $body = 's3:config probe ' . now()->toIso8601String();

        $this->line('  → Тест записи…');
        if ($fs->put($path, $body) === false) {
            throw new \RuntimeException('put() вернул false');
        }

        if (!$fs->exists($path)) {
            throw new \RuntimeException('файл не найден после записи');
        }

        $read = $fs->get($path);
        if ($read !== $body) {
            throw new \RuntimeException('содержимое не совпадает');
        }

        $fs->delete($path);
        $this->line('  ✔ Запись/чтение/удаление OK');
    }

    protected function publicReadPolicy(string $bucket): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Sid'       => 'PublicReadGetObject',
                    'Effect'    => 'Allow',
                    'Principal' => '*',
                    'Action'    => ['s3:GetObject'],
                    'Resource'  => ["arn:aws:s3:::{$bucket}/*"],
                ],
            ],
        ];
    }
}
