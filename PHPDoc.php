<?php declare(strict_types=1);

namespace Salient\PHPDoc;

use Salient\Contract\Core\Entity\Readable;
use Salient\Core\Concern\ReadsProtectedProperties;
use Salient\PHPDoc\Exception\InvalidTagValueException;
use Salient\PHPDoc\Tag\AbstractTag;
use Salient\PHPDoc\Tag\ParamTag;
use Salient\PHPDoc\Tag\ReturnTag;
use Salient\PHPDoc\Tag\TemplateTag;
use Salient\PHPDoc\Tag\VarTag;
use Salient\Utility\Arr;
use Salient\Utility\Regex;
use Salient\Utility\Str;
use InvalidArgumentException;
use OutOfRangeException;
use UnexpectedValueException;

/**
 * A PSR-5 PHPDoc
 *
 * Summaries that break over multiple lines are unwrapped. Descriptions and tags
 * may contain Markdown, including fenced code blocks.
 *
 * @property-read string|null $Summary Summary (if provided)
 * @property-read string|null $Description Description (if provided)
 * @property-read string[] $Tags Original tags, in order of appearance
 * @property-read array<string,string[]> $TagsByName Original tag metadata, indexed by tag name
 * @property-read array<string,ParamTag> $Params "@param" tags, indexed by name
 * @property-read ReturnTag|null $Return "@return" tag (if provided)
 * @property-read VarTag[] $Vars "@var" tags
 * @property-read array<string,TemplateTag> $Templates "@template" tags, indexed by name
 * @property-read class-string|null $Class
 * @property-read string|null $Member
 */
final class PHPDoc implements Readable
{
    use ReadsProtectedProperties;

    private const PHP_DOCBLOCK = '`^' . PHPDocRegex::PHP_DOCBLOCK . '$`D';
    private const PHPDOC_TAG = '`^' . PHPDocRegex::PHPDOC_TAG . '`';
    private const PHPDOC_TYPE = '`^' . PHPDocRegex::PHPDOC_TYPE . '$`D';
    private const NEXT_PHPDOC_TYPE = '`^' . PHPDocRegex::PHPDOC_TYPE . '`';

    private const STANDARD_TAGS = [
        'param',
        'readonly',
        'return',
        'throws',
        'var',
        'template',
        'template-covariant',
        'template-contravariant',
        'internal',
    ];

    protected ?string $Summary = null;
    protected ?string $Description = null;
    /** @var string[] */
    protected array $Tags = [];
    /** @var array<string,string[]> */
    protected array $TagsByName = [];
    /** @var array<string,ParamTag> */
    protected array $Params = [];
    protected ?ReturnTag $Return = null;
    /** @var VarTag[] */
    protected array $Vars = [];
    /** @var array<string,TemplateTag> */
    protected array $Templates = [];
    /** @var class-string|null */
    protected ?string $Class;
    protected ?string $Member;
    /** @var string[] */
    private array $Lines;
    private ?string $NextLine;

    /**
     * Creates a new PHPDoc object from a PHP DocBlock
     *
     * @param class-string|null $class
     */
    public function __construct(
        string $docBlock,
        ?string $classDocBlock = null,
        ?string $class = null,
        ?string $member = null
    ) {
        if (!Regex::match(self::PHP_DOCBLOCK, $docBlock, $matches)) {
            throw new InvalidArgumentException('Invalid DocBlock');
        }

        $this->Class = $class;
        $this->Member = $member;

        // - Remove comment delimiters
        // - Normalise line endings
        // - Remove leading asterisks and trailing whitespace
        // - Trim the entire PHPDoc
        // - Split into string[]
        $this->Lines = explode("\n", trim(Regex::replace(
            '/(?:^\h*+\* ?|\h+$)/m',
            '',
            Str::setEol($matches['content']),
        )));

        $this->NextLine = reset($this->Lines);

        if (!Regex::match(self::PHPDOC_TAG, $this->NextLine)) {
            $this->Summary = Str::coalesce(
                $this->getLinesUntil('/^$/', true, true),
                null,
            );

            if (
                $this->NextLine !== null
                && !Regex::match(self::PHPDOC_TAG, $this->NextLine)
            ) {
                $this->Description = rtrim($this->getLinesUntil(self::PHPDOC_TAG));
            }
        }

        $index = -1;
        while ($this->Lines && Regex::match(
            self::PHPDOC_TAG,
            $text = $this->getLinesUntil(self::PHPDOC_TAG),
            $matches,
        )) {
            $this->Tags[++$index] = $text;

            // Remove the tag name and any subsequent whitespace
            $text = ltrim(substr($text, strlen($matches[0])));
            $tag = ltrim($matches['tag'], '\\');
            $this->TagsByName[$tag][] = $text;

            // Use `strtok(" \t\n\r")` to extract metadata that may be followed
            // by a multi-line description, otherwise the first word of any
            // descriptions that start on the next line will be extracted too
            $metaCount = 0;
            switch ($tag) {
                // @param [type] $<name> [description]
                case 'param':
                    $text = $this->removeType($text, $type);
                    $token = strtok($text, " \t\n\r");
                    if ($token === false) {
                        $this->throw('No name', $tag);
                    }
                    $reference = false;
                    if ($token[0] === '&') {
                        $reference = true;
                        $token = $this->maybeExpandToken(substr($token, 1), $metaCount);
                    }
                    $variadic = false;
                    if (substr($token, 0, 3) === '...') {
                        $variadic = true;
                        $token = $this->maybeExpandToken(substr($token, 3), $metaCount);
                    }
                    if ($token !== '' && $token[0] !== '$') {
                        $this->throw("Invalid name '%s'", $tag, $token);
                    }
                    $name = rtrim(substr($token, 1));
                    if ($name !== '') {
                        $metaCount++;
                        $this->Params[$name] = new ParamTag(
                            $name,
                            $type,
                            $reference,
                            $variadic,
                            $this->removeValues($text, $metaCount),
                            $class,
                            $member,
                        );
                    }
                    break;

                // @return <type> [description]
                case 'return':
                    $text = $this->removeType($text, $type);
                    if ($type === null) {
                        $this->throw('No type', $tag);
                    }
                    $this->Return = new ReturnTag(
                        $type,
                        $this->removeValues($text, $metaCount),
                        $class,
                        $member,
                    );
                    break;

                // @var <type> [$<name>] [description]
                case 'var':
                    $name = null;
                    // Assume the first token is a type
                    $text = $this->removeType($text, $type);
                    if ($type === null) {
                        $this->throw('No type', $tag);
                    }
                    $token = strtok($text, " \t");
                    // Also assume that if a name is given, it's for a variable
                    // and not a constant
                    if ($token !== false && $token[0] === '$') {
                        $name = rtrim(substr($token, 1));
                        $metaCount++;
                    }

                    $var = new VarTag(
                        $type,
                        $name,
                        $this->removeValues($text, $metaCount),
                        $class,
                        $member,
                    );
                    if ($name !== null) {
                        $this->Vars[$name] = $var;
                    } else {
                        $this->Vars[] = $var;
                    }
                    break;

                // - @template <name> [of <type>]
                // - @template-(covariant|contravariant) <name> [of <type>]
                case 'template-covariant':
                case 'template-contravariant':
                case 'template':
                    $token = strtok($text, " \t");
                    if ($token === false) {
                        $this->throw('No name', $tag);
                    }
                    $name = rtrim($token);
                    $metaCount++;
                    $token = strtok(" \t");
                    $type = 'mixed';
                    if ($token === 'of' || $token === 'as') {
                        $metaCount++;
                        $token = strtok('');
                        if ($token !== false) {
                            $metaCount++;
                            $this->removeType($token, $type);
                        }
                    }
                    /** @var "covariant"|"contravariant"|null */
                    $variance = explode('-', $tag, 2)[1] ?? null;
                    $this->Templates[$name] = new TemplateTag(
                        $name,
                        $type,
                        $variance,
                        $class,
                        $member,
                    );
                    break;
            }
        }

        // Release strtok's copy of the string most recently passed to it
        strtok('', '');

        // Rearrange this:
        //
        //     /**
        //      * Summary
        //      *
        //      * @var int Description.
        //      */
        //
        // Like this:
        //
        //     /**
        //      * Summary
        //      *
        //      * Description.
        //      *
        //      * @var int
        //      */
        //
        if (count($this->Vars) === 1) {
            $var = reset($this->Vars);
            $description = $var->getDescription();
            if ($description !== null) {
                if ($this->Summary === null) {
                    $this->Summary = $description;
                } elseif ($this->Summary !== $description) {
                    $this->Description
                        .= ($this->Description !== null ? "\n\n" : '')
                        . $description;
                }
                $key = key($this->Vars);
                $this->Vars[$key] = $var->withDescription(null);
            }
        }

        // Remove empty strings, reducing tags with no content to an empty array
        foreach ($this->TagsByName as &$tags) {
            $tags = Arr::whereNotEmpty($tags);
        }
        unset($tags);

        // Merge @template types from the declaring class, if available
        if ($classDocBlock !== null) {
            $phpDoc = new self($classDocBlock, null, $class);
            foreach ($phpDoc->Templates as $name => $tag) {
                $this->Templates[$name] ??= $tag;
            }
        }
    }

    private function maybeExpandToken(
        string $token,
        int &$metaCount,
        string $delimiters = " \t"
    ): string {
        if ($token === '') {
            $token = strtok($delimiters);
            if ($token === false) {
                return '';
            }
            $metaCount++;
        }
        return $token;
    }

    /**
     * Remove a PHPDoc type and any subsequent whitespace from the given text
     *
     * If a PHPDoc type is found at the start of `$text`, it is assigned to
     * `$type` and removed from `$text` before it is left-trimmed and returned.
     * Otherwise, `null` is assigned to `$type` and `$text` is returned as-is.
     *
     * @param-out string|null $type
     */
    private function removeType(string $text, ?string &$type): string
    {
        if (Regex::match(self::NEXT_PHPDOC_TYPE, $text, $matches, \PREG_OFFSET_CAPTURE)) {
            [$type, $offset] = $matches[0];
            return ltrim(substr_replace($text, '', $offset, strlen($type)));
        }
        $type = null;
        return $text;
    }

    /**
     * Remove whitespace-delimited values from the given text, then trim and
     * return it if non-empty, otherwise return null
     */
    private function removeValues(string $text, int $count): ?string
    {
        return Str::coalesce(rtrim(Regex::split('/\s++/', $text, $count + 1)[$count] ?? ''), null);
    }

    /**
     * Collect and implode $this->NextLine and subsequent lines until, but not
     * including, the next line that matches $pattern
     *
     * If `$unwrap` is `false`, `$pattern` is ignored between code fences, which
     * start and end when a line contains 3 or more backticks or tildes and no
     * other text aside from an optional info string after the opening fence.
     *
     * @param bool $discard If `true`, lines matching `$pattern` are discarded,
     * otherwise they are left in {@see $this->Lines}.
     * @param bool $unwrap If `true`, lines are joined with " " instead of "\n".
     *
     * @phpstan-impure
     */
    private function getLinesUntil(
        string $pattern,
        bool $discard = false,
        bool $unwrap = false
    ): string {
        $lines = [];
        $inFence = false;

        do {
            $lines[] = $line = $this->getLine();

            if (!$unwrap) {
                if (
                    (!$inFence && Regex::match('/^(```+|~~~+)/', $line, $fence))
                    || ($inFence && isset($fence[0]) && $line === $fence[0])
                ) {
                    $inFence = !$inFence;
                }

                if ($inFence) {
                    continue;
                }
            }

            if ($this->NextLine === null) {
                break;
            }

            if (Regex::match($pattern, $this->NextLine)) {
                if (!$discard) {
                    break;
                }
                do {
                    $this->getLine();
                    if (
                        $this->NextLine === null
                        || !Regex::match($pattern, $this->NextLine)
                    ) {
                        break 2;
                    }
                } while (true);
            }
        } while ($this->Lines);

        if ($inFence) {
            throw new UnexpectedValueException('Unterminated code fence in DocBlock');
        }

        return implode($unwrap ? ' ' : "\n", $lines);
    }

    /**
     * Shift the next line off the beginning of $this->Lines, assign its
     * successor to $this->NextLine, and return it
     *
     * @phpstan-impure
     */
    private function getLine(): string
    {
        if (!$this->Lines) {
            // @codeCoverageIgnoreStart
            throw new OutOfRangeException('No more lines');
            // @codeCoverageIgnoreEnd
        }

        $line = array_shift($this->Lines);
        $this->NextLine = $this->Lines ? reset($this->Lines) : null;

        return $line;
    }

    public function unwrap(?string $value): ?string
    {
        return $value === null
            ? null
            : Regex::replace('/\s++/', ' ', $value);
    }

    /**
     * Get the PHPDoc's template tags, optionally including class templates and
     * any templates inherited from parent classes
     *
     * @return array<string,TemplateTag>
     */
    public function getTemplates(bool $all = false): array
    {
        if ($this->Class === null || $all) {
            return $this->Templates;
        }

        foreach ($this->Templates as $name => $template) {
            if (
                $template->getClass() !== $this->Class
                || ($this->Member !== null && $template->getMember() !== $this->Member)
            ) {
                continue;
            }
            $templates[$name] = $template;
        }

        return $templates ?? [];
    }

    /**
     * True if the PHPDoc contains more than a summary and/or variable type
     * information
     */
    public function hasDetail(): bool
    {
        if ($this->Description !== null) {
            return true;
        }

        foreach ([...$this->Params, $this->Return, ...$this->Vars] as $tag) {
            if (
                $tag
                && ($description = $tag->getDescription()) !== null
                && $description !== $this->Summary
            ) {
                return true;
            }
        }

        if (array_filter(
            array_diff_key($this->TagsByName, array_flip(self::STANDARD_TAGS)),
            fn(string $key): bool =>
                !Regex::match('/^(phpstan|psalm)-/', $key),
            \ARRAY_FILTER_USE_KEY
        )) {
            return true;
        }

        return false;
    }

    private function mergeTag(?AbstractTag &$ours, ?AbstractTag $theirs): void
    {
        if ($theirs === null) {
            return;
        }

        if ($ours === null) {
            $ours = $theirs;
            return;
        }

        $ours = $ours->inherit($theirs);
    }

    /**
     * Add missing values from an instance that represents the same structural
     * element in a parent class or interface
     */
    public function mergeInherited(PHPDoc $parent): void
    {
        $this->Summary ??= $parent->Summary;
        $this->Description ??= $parent->Description;
        $this->Tags = Arr::extend($this->Tags, ...$parent->Tags);
        foreach ($parent->TagsByName as $name => $tags) {
            $this->TagsByName[$name] = Arr::extend(
                $this->TagsByName[$name] ?? [],
                ...$tags,
            );
        }
        foreach ($parent->Params as $name => $theirs) {
            $this->mergeTag($this->Params[$name], $theirs);
        }
        $this->mergeTag($this->Return, $parent->Return);
        if (isset($parent->Vars[0])) {
            $this->mergeTag($this->Vars[0], $parent->Vars[0]);
        }
        foreach ($parent->Templates as $name => $theirs) {
            $this->Templates[$name] ??= $theirs;
        }
    }

    /**
     * @param array<class-string|int,string> $docBlocks
     * @param array<class-string|int,string|null>|null $classDocBlocks
     * @param class-string $fallbackClass
     */
    public static function fromDocBlocks(
        array $docBlocks,
        ?array $classDocBlocks = null,
        ?string $member = null,
        ?string $fallbackClass = null
    ): ?self {
        if (!$docBlocks) {
            return null;
        }
        foreach ($docBlocks as $key => $docBlock) {
            $class = is_string($key) ? $key : null;
            $phpDoc = new self(
                $docBlock,
                $classDocBlocks[$key] ?? null,
                $class ?? $fallbackClass,
                $member,
            );

            if ($phpDoc->Summary === null
                && $phpDoc->Description === null
                && (!$phpDoc->Tags
                    || array_keys($phpDoc->TagsByName) === ['inheritDoc'])) {
                continue;
            }

            $parser ??= $phpDoc;

            if ($phpDoc !== $parser) {
                $parser->mergeInherited($phpDoc);
            }
        }

        return $parser ?? null;
    }

    /**
     * Normalise a PHPDoc type
     *
     * If `$strict` is `true`, an exception is thrown if `$type` is not a valid
     * PHPDoc type.
     */
    public static function normaliseType(string $type, bool $strict = false): string
    {
        if (!Regex::match(self::PHPDOC_TYPE, trim($type), $matches)) {
            if ($strict) {
                throw new InvalidArgumentException(sprintf(
                    "Invalid PHPDoc type '%s'",
                    $type,
                ));
            }
            return self::replace([$type])[0];
        }

        $types = Str::splitDelimited('|', $type, true, null, Str::PRESERVE_QUOTED);

        // Move `null` to the end of union types
        $notNull = [];
        foreach ($types as $t) {
            $t = ltrim($t, '?');
            if (strcasecmp($t, 'null')) {
                $notNull[] = $t;
            }
        }

        if ($notNull !== $types) {
            $types = $notNull;
            $nullable = true;
        }

        // Simplify composite types
        $phpTypeRegex = Regex::delimit('^' . Regex::PHP_TYPE . '$', '/');
        foreach ($types as &$type) {
            $brackets = false;
            if ($type !== '' && $type[0] === '(' && $type[-1] === ')') {
                $brackets = true;
                $type = substr($type, 1, -1);
            }
            $split = array_unique(self::replace(explode('&', $type)));
            $type = implode('&', $split);
            if ($brackets && (
                count($split) > 1
                || !Regex::match($phpTypeRegex, $type)
            )) {
                $type = "($type)";
            }
        }

        $types = array_unique(self::replace($types));
        if ($nullable ?? false) {
            $types[] = 'null';
        }

        return implode('|', $types);
    }

    /**
     * @param string[] $types
     * @return string[]
     */
    private static function replace(array $types): array
    {
        return Regex::replace(
            ['/\bclass-string<(?:mixed|object)>/i', '/(?:\bmixed&|&mixed\b)/i'],
            ['class-string', ''],
            $types,
        );
    }

    /**
     * @param string|int|float ...$args
     * @return never
     */
    private function throw(string $message, ?string $tag, ...$args): void
    {
        if ($tag !== null) {
            $message .= ' for @%s';
            $args[] = $tag;
        }

        $message .= ' in DocBlock';

        if (isset($this->Class)) {
            $message .= ' of %s';
            $args[] = $this->Class;
            if (isset($this->Member)) {
                $message .= '::%s';
                $args[] = $this->Member;
            }
        }

        throw new InvalidTagValueException(sprintf($message, ...$args));
    }
}
