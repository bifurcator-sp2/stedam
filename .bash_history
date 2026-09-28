./vendor/bin/sail artisan make:filament-resource User
echo $USER
mc
./vendor/bin/sail up -d
./vendor/bin/sail artisan make:filament-resource User
stedam@bifurcator-home:~$ ./vendor/bin/sail artisan make:filament-resource User
Docker or Podman is not running.
stedam@bifurcator-home:~$sudo usermod -aG docker stedam
sudo usermod -aG docker stedam
getent group docker
mc
./vendor/bin/sail artisan make:filament-resource User
sudo chown -R stedam:stedam /home/stedam
sudo chmod -R 775 storage bootstrap/cache
./vendor/bin/sail down
./vendor/bin/sail up -d
./vendor/bin/sail artisan make:filament-resource User
./vendor/bin/sail up -d
mc
./vendor/bin/sail artisan install:api
./vendor/bin/sail up -d
./vendor/bin/sail artisan install:api
composer require laravel/breeze --dev
composer require laravel/breeze --dev
./vendor/bin/sail artisan breeze:install
npm install @qirolab/nuxt-sanctum-authentication
npx nuxt generate
npx nuxt generate
su - root
./vendor/bin/sail up -d
su - root
./vendor/bin/sail exec laravel.test ls -la /etc/apache2/
./vendor/bin/sail exec laravel.test bash -c "which nginx apache2 caddy php-fpm"
./vendor/bin/sail exec laravel.test bash -c "which nginx apache2 caddy php-fpm"
./vendor/bin/sail exec laravel.test bash -c "ls /etc/nginx /etc/apache2 /etc/caddy 2>/dev/null"
stedam@bifurcator-home:~$ ./vendor/bin/sail exec laravel.test bash -c "ls /etc/nginx /etc/apache2 /etc/caddy 2>/dev/null"
.
./vendor/bin/sail exec laravel.test bash -c "which nginx apache2 caddy php-fpm"
./vendor/bin/sail exec laravel.test bash -c "ls /etc/nginx /etc/apache2 /etc/caddy 2>/dev/null"
./vendor/bin/sail exec laravel.test bash -c "ps aux | grep -E 'nginx|apache|caddy|php-fpm' | grep -v grep"
docker exec sail-proxy ls -la /etc/nginx/
./vendor/bin/sail exec laravel.test ps aux
./vendor/bin/sail exec laravel.test ps aux
cat /home/stedam/vendor/laravel/sail/runtimes/8.5/Dockerfile | grep -A 5 -B 5 "CMD\|ENTRYPOINT\|php -S"
cd /home/stedam
docker compose down
docker compose up -d
docker compose down
docker compose up -d
docker exec stedam-laravel.test-1 ps aux | grep "php.*-S"
./vendor/bin/sail artisan route:clear
./vendor/bin/sail artisan optimize:clear
./vendor/bin/sail artisan route:list | grep "app/{any"
./vendor/bin/sail artisan route:clear
./vendor/bin/sail artisan optimize:clear
./vendor/bin/sail artisan route:list | grep -E "app"
docker exec stedam-laravel.test-1 ls -la /var/www/html/public/app/index.html
docker exec stedam-laravel.test-1 ls -la /var/www/html/public/app/index.html
mc
./vendor/bin/sail up -d
composer require spatie/laravel-permission
./vendor/bin/sail artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
ls config/permission.php
ls database/migrations/ | grep permission
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan optimize:clear
./vendor/bin/sail artisan tinker
./vendor/bin/sail artisan make:filament-resource Role
./vendor/bin/sail artisan make:filament-resource Role
./vendor/bin/sail up -d
./vendor/bin/sail artisan make:filament-resource Role
php  artisan make:filament-resource Role
./vendor/bin/sail artisan optimize:clear
./vendor/bin/sail up -d
./vendor/bin/sail up -d
./vendor/bin/sail up -d
./vendor/bin/sail artisan make:migration create_role_translations_table
php artisan make:migration create_role_translations_table
php artisan make:model RoleTranslation
php artisan make:model Role
php artisan migrate
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan make:middleware SetLocale
php artisan make:middleware SetLocale
./vendor/bin/sail artisan make:controller Api/RoleController
php artisan make:controller Api/RoleController
php artisan make:controller Api/RoleController
php artisan make:migration create_countries_table
php artisan make:migration create_country_translations_table
php artisan migrate
./vendor/bin/sail artisan migrate
php artisan make:filament-resource Country
php artisan make:filament-resource Country
./vendor/bin/sail artisan make:filament-resource Country
