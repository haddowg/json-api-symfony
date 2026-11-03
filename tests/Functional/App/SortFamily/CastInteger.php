<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SortFamily;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * `CAST_INTEGER(expr)`, the DQL function an application registers to cast text to an
 * integer, since DQL has no `CAST`.
 */
final class CastInteger extends FunctionNode
{
    private Node|string $expression = '';

    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf('CAST(%s AS INTEGER)', $sqlWalker->walkArithmeticPrimary($this->expression));
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
