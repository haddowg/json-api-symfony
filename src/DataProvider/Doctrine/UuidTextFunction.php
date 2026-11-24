<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * `JSONAPI_UUID_TEXT(path)`: a uuid id read as its canonical lowercase text, through
 * {@see UuidText} for the connection's platform.
 *
 * @internal
 */
final class UuidTextFunction extends FunctionNode
{
    public Node|string $path = '';

    public function getSql(SqlWalker $sqlWalker): string
    {
        return UuidText::sql($sqlWalker->getConnection()->getDatabasePlatform(), $this->path instanceof Node ? $this->path->dispatch($sqlWalker) : $this->path);
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->path = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
