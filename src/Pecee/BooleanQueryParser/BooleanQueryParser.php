<?php

namespace Pecee\BooleanQueryParser;

class BooleanQueryParser
{
    protected $splitter;

    public const AND_TOKEN = 'and';
    public const OR_TOKEN = 'or';
    public const NOT_TOKEN = 'not';
    public const AND_TOKEN_CHARACTER = '+';
    public const OR_TOKEN_CHARACTER = '§'; // OR in MySQL is nothing, so we need to define a character that isn't commonly used
    public const NOT_TOKEN_CHARACTER = '-';
    public const LEFT_BRACKET_TOKEN_CHARACTER = '(';
    public const RIGHT_BRACKET_TOKEN_CHARACTER = ')';

    public function __construct()
    {
        $this->splitter = new QuerySplitter();
    }

    /**
     * This will take a boolean search string, and will convert it into MySQL Fulltext
     *
     * @param string $string
     *
     * @return string|null
     */
    public function parse(string $string): ?string
    {
        // Clean the string and make it all lowercase - we can save on this operation later making code cleaner
        $string = $this->firstClean($string);

        if ((substr_count($string, '"') % 2 === 0) === false) {
            return null;
        }

        $tokens = $this->splitIntoTokens($string);

        // Quoted strings need to be untouched
        $tokens = $this->mergeQuotedStrings($tokens);

        if ($this->isBalanced($tokens) === false) {
            return null;
        }

        // Clean the words of anything we dont want
        $tokens = $this->secondClean($tokens);

        // Any hyphenated words should be merged to they are taken as is (john-paul should be "john-paul" not +john -paul)
        $tokens = $this->mergeHyphenatedWords($tokens);

        // Merge any asterisk against the trailing word (not phrase)
        $tokens = $this->processAsterisk($tokens);

        // Clear any empty entries - makes it easier to work with
        $tokens = $this->clearSpaces($tokens);

        // Convert operators to tokens
        $tokens = $this->removeLeadingTrailingOperators($tokens);

        // process OR keywords
        $tokens = $this->process($tokens, static::OR_TOKEN, static::OR_TOKEN_CHARACTER);

        // process AND keywords
        $tokens = $this->process($tokens, static::AND_TOKEN, static::AND_TOKEN_CHARACTER);

        // Change NOT's to -
        $tokens = $this->processNot($tokens);

        // EVERYTHING AT THIS POINT SHOULD NOW HAVE CORRECT OPERATORS INFRONT OF THEM
        // At this point there may be multiple operators in front of a token. The next step is to prioritise these.
        // If this "group" of operator tokens contains a "-", then remove all but this one, its top dog
        $tokens = $this->cleanStackedOperators($tokens);

        // Each token now has 0 or 1 operator(s) in front of it - anything that has 0 operators needs a "+"
        $tokens = $this->addMissingAndOperators($tokens);

        // Lets clean everything up now and merge it all back together
        $resultString = $this->finalClean(implode(' ', $tokens));

        return trim($resultString);
    }

    /**
     * First pass over the initial string to clean some elements
     *
     * @param string $string
     *
     * @return string
     */
    protected function firstClean(string $string): string
    {
        $output = str_ireplace('title:', ' ', $string);
        $output = str_replace(['{', '[', '}', ']', '“', '”'], ['(', '(', ')', ')', '"', '"'], $output);
        $output = preg_replace('# +#', ' ', $output);
        $output = preg_replace('#^\s+#m', '', $output);
        $output = preg_replace('#\s+$#m', '', $output);
        $output = preg_replace('#\n+#', "\n", $output);
        $output = preg_replace('#^\ +#', '', $output);
        $output = preg_replace('#^&nbsp;$#im', '', $output);
        $output = preg_replace('/((\b-\s)|(\s-\s))/', ' ', $output);
        $output = preg_replace('/\s\s+/', ' ', $output);

        return strtolower(trim($output));
    }

    /**
     * We need to process each element in turn now and clean/sanitise it
     *
     * @param array $tokens
     *
     * @return array
     */
    protected function secondClean(array $tokens): array
    {
        $toReturn = [];

        foreach ($tokens as $token) {
            $token = preg_replace('/[^\p{L}0-9 @\(\)\-\+\*\"\.]/ui', '', $token);
            $toReturn[] = $token;
        }

        return $toReturn;
    }

    /**
     * Because we don't want hyphenated words to be treated differently, lets merge them in quotes
     *
     * @param array $tokens
     *
     * @return array
     */
    protected function mergeHyphenatedWords(array $tokens): array
    {
        $toReturn = [];

        $tokenCount = \count($tokens);

        if ($tokenCount < 3) {
            return $tokens;
        }

        for ($i = 0; $i < $tokenCount; $i++) {
            if ($i === 0 || $i === ($tokenCount - 1)) {
                $toReturn[] = $tokens[$i];
                continue; // We can't consider first or last tokens here..
            }

            $previous = $i - 1;
            $current = $i;
            $next = $i + 1;

            // Because quotes are merged, lets make sure we dont touch these
            // If the first character of the previous, current, or next entries begin with ", ignore
            if ($tokens[$previous][0] === '"' || $tokens[$current][0] === '"' || $tokens[$next][0] === '"') {
                $toReturn[] = $tokens[$current];
                continue;
            }

            if ($tokens[$current] === '-') {
                if (trim($tokens[$previous]) !== '' && trim($tokens[$next]) !== '') {
                    // The previous and next tokens aren't empty spaces, so this must be a hyphenated thingy
                    array_pop($toReturn);
                    $toReturn[] = '"' . $tokens[$previous] . $tokens[$current] . $tokens[$next] . '"';
                    $i++;
                } else {
                    $toReturn[] = $tokens[$current];
                }
            } else {
                $toReturn[] = $tokens[$current];
            }
        }

        return $toReturn;
    }

    /**
     * Merge asterisks against the last entry
     *
     * @param array $tokens
     *
     * @return array
     */
    protected function processAsterisk(array $tokens): array
    {
        $toReturn = [];

        $tokenCount = \count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            if ($i === 0) {
                $toReturn[] = $tokens[$i];
                continue; // Ignore the first entry
            }

            $current = $i;

            if ($tokens[$current] === '*') {
                // If the current entry is an asterisk, then merge it with the previous entry
                $lastEntry = array_pop($toReturn);
                $toReturn[] = $lastEntry . $tokens[$current];
            } else {
                $toReturn[] = $tokens[$current];
            }
        }

        return $toReturn;
    }

    /**
     * Don't just count the brackets, make sure they're in order!
     *
     * @param array $tokens
     *
     * @return bool
     */
    protected function isBalanced(array $tokens): bool
    {
        $balanced = 0;

        foreach ($tokens as $token) {
            if ($token === '(') {
                $balanced++;
            } elseif ($token === ')') {
                $balanced--;
            }

            if ($balanced < 0) {
                return false;
            }
        }

        return ($balanced === 0);
    }

    /**
     * Split a string into an array of 'tokens'
     *
     * @param string $string
     *
     * @return array
     */
    protected function splitIntoTokens(string $string): array
    {
        $tokens = [];
        $token = '';

        $splitLen = $this->splitter->getMaxLengthOfSplitter();
        $len = \strlen($string);
        $pos = 0;

        while ($pos < $len) {

            for ($i = $splitLen; $i > 0; $i--) {
                $substr = substr($string, $pos, $i);
                if ($this->splitter->isSplitter($substr)) {

                    if ($token !== '') {
                        $tokens[] = $token;
                    }

                    $tokens[] = $substr;
                    $pos += $i;
                    $token = '';

                    continue 2;
                }
            }

            $token .= $string[$pos];
            $pos++;
        }

        if ($token !== '') {
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Quoted strings wont be touched, so lets merge any relevant tokens
     *
     * @param array $tokens
     *
     * @return array
     */
    protected function mergeQuotedStrings(array $tokens): array
    {
        $token_count = \count($tokens);
        $i = 0;
        while ($i < $token_count) {
            if ($tokens[$i] !== '"') {
                $i++;
                continue;
            }
            $count = 1;
            for ($n = $i + 1; $n < $token_count; $n++) {
                $token = $tokens[$n];

                if ($token === '"') {
                    $count--;
                }

                $tokens[$i] .= $token;
                unset($tokens[$n]);
                if ($count === 0) {
                    $n++;
                    break;
                }
            }
            $i = $n;
        }

        return array_values($tokens);
    }

    /**
     * Remove all empty elements from an array
     *
     * @param array $tokens
     *
     * @return array
     */
    protected function clearSpaces(array $tokens): array
    {
        $toReturn = [];

        $max = \count($tokens);
        for ($i = 0; $i < $max; $i++) {
            if (trim($tokens[$i]) !== '') {
                $toReturn[] = $tokens[$i];
            }
        }

        return $toReturn;
    }

    /**
     * Remove any leading or trailing operators, we don't need them and they don't apply here
     * Also works within brackets
     *
     * @param array $tokens
     *
     * @return array
     */
    protected function removeLeadingTrailingOperators(array $tokens): array
    {
        $arrayTouched = false;
        $stopOperators = [static::AND_TOKEN, static::OR_TOKEN, static::AND_TOKEN_CHARACTER, static::OR_TOKEN_CHARACTER];

        foreach ($tokens as $key => $element) {
            if (\in_array($element, [static::AND_TOKEN, static::OR_TOKEN, static::AND_TOKEN_CHARACTER, static::OR_TOKEN_CHARACTER, static::NOT_TOKEN, static::NOT_TOKEN_CHARACTER], true) &&
                (
                    ((!isset($tokens[$key - 1]) || (\in_array($tokens[$key - 1], $stopOperators, true) || $tokens[$key - 1] === static::LEFT_BRACKET_TOKEN_CHARACTER)) && $element !== static::NOT_TOKEN && $element !== static::NOT_TOKEN_CHARACTER) ||
                    (!isset($tokens[$key + 1]) || (\in_array($tokens[$key + 1], $stopOperators, true) || $tokens[$key + 1] === static::RIGHT_BRACKET_TOKEN_CHARACTER))
                )) {
                $arrayTouched = true;
                unset($tokens[$key]);
                if (($element === static::NOT_TOKEN || $element === static::NOT_TOKEN_CHARACTER) && isset($tokens[$key - 1]) && \in_array($tokens[$key - 1], $stopOperators, true)) {
                    unset($tokens[$key - 1]);
                }
            }
        }

        return $arrayTouched ? array_values($tokens) : $tokens;
    }

    /**
     * After processing stuff, we might find operators stacked up against tokens, like "+++-manager"
     * Lets clean them up here
     *
     * @param array $tokens
     *
     * @return array
     */
    protected function cleanStackedOperators(array $tokens): array
    {
        $toReturn = [];

        $tokenCount = \count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            $current = $i;

            if (\in_array($tokens[$current], [static::AND_TOKEN, static::OR_TOKEN, static::NOT_TOKEN, static::AND_TOKEN_CHARACTER, static::OR_TOKEN_CHARACTER, static::NOT_TOKEN_CHARACTER], true)) {
                // Okay, so we're at an element that is a entity, lets look forward to find all entities
                $entities = '';
                $toSkip = -1;
                for ($x = $i; $x < $tokenCount; $x++) {
                    if (\in_array($tokens[$x], [static::AND_TOKEN, static::OR_TOKEN, static::NOT_TOKEN, static::AND_TOKEN_CHARACTER, static::OR_TOKEN_CHARACTER, static::NOT_TOKEN_CHARACTER], true)) {
                        $toSkip++;
                        $entities .= $tokens[$x];
                    } else {
                        break; // We're done going forward
                    }
                }

                // NOT takes priority over all
                if (strpos($entities, static::NOT_TOKEN_CHARACTER) !== false) {
                    // Token was found
                    $toReturn[] = static::NOT_TOKEN_CHARACTER;
                } elseif (strpos($entities, static::OR_TOKEN_CHARACTER) !== false) {
                    // FOUND an OR operator
                    $toReturn[] = static::OR_TOKEN_CHARACTER;
                } else {
                    // We've found some operators, but not matching anything else. Must be and's!
                    $toReturn[] = static::AND_TOKEN_CHARACTER;
                }

                $i += $toSkip;
            } else {
                $toReturn[] = $tokens[$current];
            }
        }

        return $toReturn;
    }

    /**
     * If a token has no operators in front of it by now, add an AND operator in front of it
     *
     * @param array $tokens
     *
     * @return array
     */
    protected function addMissingAndOperators(array $tokens): array
    {
        $toReturn = [];

        $tokenCount = \count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            $previous = (($i - 1) >= 0 ? $i - 1 : 0);
            $current = $i;

            if (\in_array($tokens[$current], [
                static::AND_TOKEN, static::OR_TOKEN, static::NOT_TOKEN, static::AND_TOKEN_CHARACTER, static::OR_TOKEN_CHARACTER, static::NOT_TOKEN_CHARACTER,
                ')',
            ], true)) {
                $toReturn[] = $tokens[$current];
            } else {
                // It item is not a operator, lets check that whatever before it has one
                if (!\in_array($tokens[$previous], [static::AND_TOKEN, static::OR_TOKEN, static::NOT_TOKEN, static::AND_TOKEN_CHARACTER, static::OR_TOKEN_CHARACTER, static::NOT_TOKEN_CHARACTER], true)) {
                    // does not have operator in front of it
                    array_push($toReturn, static::AND_TOKEN_CHARACTER, $tokens[$current]);
                } else {
                    // does have operator in front of it
                    $toReturn[] = $tokens[$current];
                }
            }
        }

        return $toReturn;
    }

    /**
     * Processing AND and OR tokens are the same effectively, so this is just one method to do both
     *
     * @param array $tokens
     * @param string $tokenToFind
     * @param string $characterToReplace
     *
     * @return array
     */
    protected function process(array $tokens, string $tokenToFind, string $characterToReplace): array
    {
        $toReturn = [];

        $tokenCount = \count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            $previous = (($i - 1) >= 0 ? $i - 1 : 0);
            $current = $i;

            // If this is the tokenToFind, we want to prepend that operator to the token before and after this
            if ($tokens[$current] === $tokenToFind) {
                // So long as the previous token is not a closing bracket (which means we need to loop back to before it
                // to add the operator in)
                if ($tokens[$previous] === ')') {
                    // We now need to go back through the tokens to find the matching bracket and add this token in
                    // before it
                    $bracketCount = 1;
                    $temporaryToReturn = [];
                    $temporaryToReturn[] = array_pop($toReturn);

                    // Loop back from previous index (Because we are popping from the array, the previous index is the
                    // last entry
                    while ($bracketCount > 0) {
                        $currentToken = array_pop($toReturn);
                        if ($currentToken === ')') {
                            $bracketCount++;
                        } elseif ($currentToken === '(') {
                            $bracketCount--;
                        }
                        $temporaryToReturn[] = $currentToken;
                    }

                    // toReturn should now be at the correct location
                    $toReturn[] = $characterToReplace;
                    $toReturn = array_merge($toReturn, array_reverse($temporaryToReturn));
                    $toReturn[] = $characterToReplace;
                } else {
                    // This is good, all we should need to do here is just apply our relevant token to the previous and
                    // next elements
                    $previousToken = array_pop($toReturn);
                    array_push($toReturn, $characterToReplace, $previousToken, $characterToReplace);
                }
                continue;
            }

            $toReturn[] = $tokens[$current];
        }

        return $toReturn;
    }

    /**
     * Change NOT phrases into -'s
     *
     * @param $tokens
     *
     * @return array
     */
    protected function processNot($tokens): array
    {
        $toReturn = [];

        $max = \count($tokens);
        for ($i = 0; $i < $max; $i++) {
            if (\in_array($tokens[$i], [static::NOT_TOKEN, static::NOT_TOKEN_CHARACTER], true)) {
                $toReturn[] = static::NOT_TOKEN_CHARACTER;
            } else {
                $toReturn[] = $tokens[$i];
            }
        }

        return $toReturn;
    }

    /**
     * Last run over the combined tokens to clean stuff up
     *
     * @param string $string
     *
     * @return string
     */
    protected function finalClean(string $string): string
    {
        $output = str_replace([static::NOT_TOKEN_CHARACTER . ' ', static::AND_TOKEN_CHARACTER . ' ', static::OR_TOKEN_CHARACTER . ' '], [' ' . static::NOT_TOKEN_CHARACTER, ' ' . static::AND_TOKEN_CHARACTER, ' ' . static::OR_TOKEN_CHARACTER], $string);
        $output = preg_replace('/\s\s+/', ' ', $output); // Remove double spaces
        $output = str_replace([' )', '( '], [')', '('], $output);
        $output = str_ireplace(static::OR_TOKEN_CHARACTER, '', $output); // OR token needs to be removed

        return $output;
    }
}