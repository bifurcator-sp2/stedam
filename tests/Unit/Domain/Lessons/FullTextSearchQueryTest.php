<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Lessons;

use App\Domain\Lessons\ValueObjects\FullTextSearchQuery;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Правила построения tsquery из пользовательского ввода (PostgreSQL).
 */
final class FullTextSearchQueryTest extends TestCase
{
    #[Test]
    public function it_builds_prefix_and_query_with_mandatory_words(): void
    {
        $q = FullTextSearchQuery::fromUserInput('hello');

        $this->assertSame('hello:*', $q->toTsQuery());
        $this->assertSame('hello', $q->normalized());
        $this->assertSame(['hello'], $q->words());
    }

    #[Test]
    public function it_collapses_whitespace_and_lowercases(): void
    {
        $q = FullTextSearchQuery::fromUserInput("  MSQ   Node  ");

        $this->assertSame('msq node', $q->normalized());
        $this->assertSame('msq:* & node:*', $q->toTsQuery());
    }

    #[Test]
    public function it_strips_tsquery_operators_from_user_input(): void
    {
        // Операторы tsquery не должны попадать в запрос как синтаксис.
        $q = FullTextSearchQuery::fromUserInput('C++ & +foo | bar !baz');

        $this->assertSame('c foo bar baz', $q->normalized());
        $this->assertSame('c:* & foo:* & bar:* & baz:*', $q->toTsQuery());
    }

    #[Test]
    public function it_strips_quotes_brackets_and_punctuation(): void
    {
        $q = FullTextSearchQuery::fromUserInput('<script>alert(1)</script> "x" (y)');

        $this->assertSame('script alert 1 /script x y', $q->normalized());
        $this->assertSame('script:* & alert:* & 1:* & /script:* & x:* & y:*', $q->toTsQuery());
    }

    #[Test]
    public function it_rejects_input_without_meaningful_characters(): void
    {
        $this->expectException(DomainException::class);

        FullTextSearchQuery::fromUserInput('+-><()~*"@&|!');
    }

    #[Test]
    public function it_rejects_blank_input(): void
    {
        $this->expectException(DomainException::class);

        FullTextSearchQuery::fromUserInput('   ');
    }
}
