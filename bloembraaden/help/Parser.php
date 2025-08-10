<?php

declare(strict_types=1);

namespace Bloembraaden;

/**
 * The parser deals with 1 byte characters only to keep it fast etc., however there is no problem when
 * multibyte characters are in the strings, they will just be passed back unchanged as part of the content.
 */
class Parser extends Base
{
    // "\n" is the newline character in this parser (converted upon input)
    private array $open_tags = array(); // indexed list of tags that are open, outer to inner
    private string $text;
    private int $pointer, $slacker, $len;
    private float $started;
    private bool $escaped = false, $holding_eol = false, $is_sub_parser;
    // start the array with more specific towards less specific (e.g. ``` before `), parser starts matching at the beginning
    private array $commands = array(
        '######' => array('new_line' => true, 'allow_space' => true, 'tag' => 'h6'),
        '#####' => array('new_line' => true, 'allow_space' => true, 'tag' => 'h5'),
        '####' => array('new_line' => true, 'allow_space' => true, 'tag' => 'h4'),
        '###' => array('new_line' => true, 'allow_space' => true, 'tag' => 'h3'),
        '##' => array('new_line' => true, 'allow_space' => true, 'tag' => 'h2'),
        '#' => array('new_line' => true, 'allow_space' => true, 'tag' => 'h1'),
        '–––' => array('new_line' => true, 'allow_space' => true, 'tag' => 'hr', 'className' => 'bloembraaden-minuses'),
        '---' => array('new_line' => true, 'allow_space' => true, 'tag' => 'hr', 'className' => 'bloembraaden-minuses'),
        '+++' => array('new_line' => true, 'allow_space' => true, 'tag' => 'hr', 'className' => 'bloembraaden-pluses'),
        '***' => array('new_line' => true, 'allow_space' => true, 'tag' => 'hr', 'className' => 'bloembraaden-stars'),
        '___' => array('new_line' => true, 'allow_space' => true, 'tag' => 'hr', 'className' => 'bloembraaden-underscores'),
        '```' => array('new_line' => false, 'allow_space' => true, 'tag' => 'pre'), // also when indented by four spaces..., except when in a list
        '    ' => array('new_line' => true, 'allow_space' => true, 'tag' => 'TAB'),
        ' | ' => array('new_line' => false, 'allow_space' => true, 'tag' => 'td'),
        ' |' => array('new_line' => false, 'allow_space' => true, 'tag' => 'td'),
        '| ' => array('new_line' => true, 'allow_space' => true, 'tag' => 'tr'),
        '– ' => array('new_line' => true, 'allow_space' => true, 'tag' => 'ul', 'className' => 'minuses bloembraaden-minuses'),
        '- ' => array('new_line' => true, 'allow_space' => true, 'tag' => 'ul', 'className' => 'minuses bloembraaden-minuses'),
        '+ ' => array('new_line' => true, 'allow_space' => true, 'tag' => 'ul', 'className' => 'pluses bloembraaden-pluses'),
        '* ' => array('new_line' => true, 'allow_space' => true, 'tag' => 'ul', 'className' => 'stars bloembraaden-stars'),
        '. ' => array('new_line' => true, 'allow_space' => true, 'tag' => 'ol'),
        '>' => array('new_line' => true, 'allow_space' => true, 'tag' => 'blockquote'),
        "\n" => array('new_line' => false, 'allow_space' => true, 'tag' => 'EOL'),
        '`' => array('new_line' => false, 'allow_space' => false, 'tag' => 'code'),
        '_' => array('new_line' => false, 'allow_space' => false, 'tag' => 'em'),
        '~' => array('new_line' => false, 'allow_space' => false, 'tag' => 's'),
        '*' => array('new_line' => false, 'allow_space' => false, 'tag' => 'strong'),
        '^' => array('new_line' => false, 'allow_space' => false, 'tag' => 'span', 'className' => 'peatcms-circ bloembraaden-circ'),
        '¤' => array('new_line' => false, 'allow_space' => false, 'tag' => 'span', 'className' => 'peatcms-curr bloembraaden-curr'),
        '[ ]' => array('new_line' => false, 'allow_space' => true, 'tag' => 'check'),
        '[x]' => array('new_line' => false, 'allow_space' => true, 'tag' => 'check'),
        '![' => array('new_line' => false, 'allow_space' => true, 'tag' => 'img'),
        '[' => array('new_line' => false, 'allow_space' => false, 'tag' => 'a'),
        '<' => array('new_line' => false, 'allow_space' => false, 'tag' => 'a'),
        '§' => array('new_line' => true, 'allow_space' => true, 'tag' => 'div'),
        '=' => array('new_line' => false, 'allow_space' => false, 'tag' => 'span', 'className' => 'nowrap'),
        '@' => array('new_line' => false, 'allow_space' => false, 'tag' => 'span', 'className' => 'peatcms-email-link bloembraaden-email-link'),
        '|' => array('new_line' => false, 'allow_space' => false, 'single' => true, 'tag' => '&#173;'),
    );
    private ?LoggerInterface $logger;

    /**
     * @param LoggerInterface|null $logger
     */
    public function __construct(LoggerInterface $logger = null)
    {
        parent::__construct();
        $this->logger = $logger;
        $this->log('New parser');
        $this->started = microtime(true);
    }

    private function log(string $message): void
    {
        if (null === $this->logger) return;
        $this->logger->log($message);
    }

    /**
     * Parses the text completely and returns the result as html string, returns empty string if given $text is null
     * @param string|null $text the text to parse
     * @param bool $as_sub_parser default false when true assumes valid html tags are around the provided string
     * @return string the parsed text
     */
    public function parse(?string $text, bool $as_sub_parser = false): string
    {
        if (null === $text) return '';
        $this->is_sub_parser = $as_sub_parser;
        // convert all newlines to "\n" and start and end with it (to capture all possible start tags)
        // this leaves extra tab characters if the line-ends were \r only, but that is pretty obscure and tab is not used in the parser so leave it
        $text = str_replace("\t\n", '', str_replace("\r", "\n\t", $text));
        // spaces (and tabs) at the end of a line are not allowed (and nonsensical anyway), compact the indexed link structure
        $text = str_replace(array(" \n", '] [', '] ('), array("\n", '][', ']('), $text);
        $this->text = $text;
        if (false === $as_sub_parser) {
            $this->log('parse as master');
            $this->text = "\n$this->text\n";
        } else {
            $this->log('parse as sub parser');
        }
        $this->len = strlen($this->text);
        $this->pointer = 0;
        $this->slacker = 0; // slacker runs behind the pointer, pointing until where text has been processed into html
        ob_start();
        while ($cmd = $this->getNextCommand()) {
            echo $this->runCommand($cmd);
        }
        // finish up
        echo $this->flush();
        echo $this->closeTags(true);
        unset($this->text);

        return ob_get_clean();
    }

    /**
     * @since 0.6.17 will output a p when necessary (to avoid #textnodes in final html output)
     */
    private function echoParagraphWhenNecessary()
    {
        // the img must be 'in' another tag unless this is a sub text
        if (false === $this->is_sub_parser && null === $this->getOpenTag()) {
            echo $this->openTag('p');
        }
    }

    private function runCommand($cmd): string
    {
        $text = $this->text;
        $pointer = $this->pointer; // the pointer is at the first character of the signature of this command
        if ((microtime(true) - $this->started) > 3) {
            $this->handleErrorAndStop(
                sprintf("Parser timeout error near %s\nTEXT:\n%s.", $pointer, strip_tags($this->text)),
                __('Parser timeout error.', 'peatcms')
            );
        }
        $tag = $cmd['tag'];
        $this->log("tag: ($tag) ");
        if ($tag === 'EOL') { // check if there is a blank line following
            if (false !== ($next_EOL = strpos($text, "\n", $pointer + 1))) {
                $line = substr($text, $pointer + 1, $next_EOL - $pointer - 1);
                $this->log("checking next line: $next_EOL " . var_export($line, true));
                if ('' === $line || '' === str_replace(array("\t", ' '), '', $line)) { // the next line is blank
                    return $this->runCommand(array(
                            'tag' => 'BLANK_LINE',
                            'new_line' => false,
                            'allow_space' => true,
                            'signature' => "\n$line\n",
                        )
                    );
                }
            }
        }
        // don't process any tags within <pre> or <code>
        if ('pre' === ($open_tag = $this->getOpenTag()) || 'code' === $open_tag) {
            if ('BLANK_LINE' !== $tag && $tag !== $open_tag) {
                $this->pointer++;

                return '';
            }
        }
        $sig = $cmd['signature'];
        $this->log("CMD [tag]: $tag");
        ob_start();
        if (isset($cmd['single'])) { // single means single character replacement
            echo $this->flush();
            echo $cmd['tag'];
        } elseif (false === $cmd['new_line']) {
            echo $this->flush();
            if ('BLANK_LINE' === $tag) { // close all previous tags
                echo $this->closeTags();
            } elseif ('EOL' === $tag) { // remember this EOL for the flusher
                if (in_array($this->getOpenTag(), array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'))) {
                    echo $this->closeTag();
                }
                $this->holding_eol = true;
            } elseif ('pre' === $tag) {
                if ('pre' === $this->getOpenTag()) { // close the current tag
                    echo $this->closeTag();
                } else {
                    echo $this->openTag($tag);
                }
            } elseif ('check' === $tag) {
                if ('[x]' === $sig) {
                    echo '<input type="checkbox" class="bloembraaden parsed" checked="checked"/>';
                } else {
                    echo '<input type="checkbox" class="bloembraaden parsed"/>';
                }
            } elseif ('img' === $tag) {
                $this->echoParagraphWhenNecessary();
                // img: the first entry between the bracket construction ![] MUST be an integer, denoting which
                // of the linked images needs to be displayed, one-based in the order you gave them in the edit screen
                // all other entries (separated by a space) will be classnames added to the image,
                // alt text will be provided by the image element
                $pointer += 2;
                if (-1 !== ($end = $this->nextPartOfCommand($text, ']', $pointer))) {
                    $str_command = trim(substr($text, $pointer, $end - $pointer));
                    $index = (int)$str_command; // default to 0...
                    echo '{%image:';
                    echo $index;
                    echo '%}<img src="{{slug}}" alt="{{excerpt}}" class="';
                    echo $str_command;
                    echo '"/>{%image:';
                    echo $index;
                    echo '%}';
                }
                $end++;
                $this->pointer = $end;
                $sig = '';
            } elseif ('a' === $tag) {
                $pointer = $this->pointer;
                $positions = $this->nextPartOfCommandOnSameLine($text, ']:', $pointer);
                // honor the eol before a link, when not on the separate id line
                if (true === $this->holding_eol && null === $positions) {
                    if ($this->getOpenTag()) echo '<br/>';
                    $this->holding_eol = false;
                }
                // the following types of links can be in the text
                // [an example](http://example.com/ "Title")
                // [About](/about/)
                // [an example][id] or [an example] [id]
                // (separate line) -> [id]: <http://www.google.com>
                // [Google]
                // (separate line) ->  [Google]: http://google.com/ "Title here"
                // separate line can also be on 2 lines
                // the pointer is at the first [ now..., or < for that matter...
                if ('<' === $sig) {
                    $pointer++;
                    if (-1 !== ($end = $this->nextPartOfCommand($text, '>', $pointer))) {
                        $url = substr($text, $pointer, $end - $pointer);
                        // link cannot contain spaces (it may contain EOLS however!):
                        if (false === str_contains($url, ' ')) {
                            $this->echoParagraphWhenNecessary();
                            echo '<a href="';
                            echo $url;
                            echo '">';
                            echo $url;
                            echo '</a>';
                            $end++;
                            $this->pointer = $end;
                            $sig = ''; // set signature to 0 chars to have the pointer remain at the set position
                        } // if it contains spaces, it's something else, just continue
                    }
                } elseif ($text[$pointer - 1] === "\n" && null !== $positions) {
                    // $sig must be '['
                    // if you're on one of the separate lines, you need to skip it entirely:
                    //echo $this->flush();
                    // check if the title for this link is on the next line, because then you need to skip that too
                    $next_EOL = $positions[1];
                    $next_line = trim(substr($text, $next_EOL,
                        ($next_next_EOL = strpos($text, "\n", $next_EOL + 1)) - $next_EOL));
                    if (($len = strlen($next_line)) > 2 && $next_line[0] === '"' and $next_line[$len - 1] === '"') {
                        $next_EOL = $next_next_EOL;
                    }
                    $this->pointer = $next_EOL;
                    $sig = ''; // set signature to 0 chars to have the pointer remain at the set position
                } else {
                    $this->echoParagraphWhenNecessary();
                    // if this is an indexed link, build it here
                    if (null !== ($positions = $this->nextPartOfCommandOnSameLine($text, ']', $pointer))
                        && ('[' !== ($next_char = $text[$positions[0] + 1]) && '(' !== $next_char)
                    ) {
                        // gets the link for when the link text is also the id
                        $link_id = substr($text, $pointer + 1, $positions[0] - $pointer - 1);
                        if (null !== ($link = $this->getLinkById($link_id, $link_id))) {
                            echo $link;
                        } else {
                            $this->addMessage(sprintf(
                                __('Incorrect link format at %1$s near %2$s.', 'peatcms'),
                                $pointer,
                                substr($text, $pointer - 5, 20)
                            ), 'warn');
                        }
                        $pointer += strlen("[$link_id]"); // go to the end of the whole tag construction
                        $this->pointer = $pointer;
                        $sig = '';
                    } elseif (null !== ($positions = $this->nextPartOfCommandOnSameLine($text, ($tag_sig = ']['), $pointer))) {
                        $tag_sig_position = $positions[0];
                        if (($closing_position = $this->nextPartOfCommand($text, ']', $tag_sig_position + 1))
                            === ($starting_position = $tag_sig_position + strlen($tag_sig))) {
                            $link_id = substr($text, $pointer + 1, $tag_sig_position - $pointer - 1);
                        } else {
                            $link_id = substr($text, $starting_position, $closing_position - $starting_position);
                        }
                        $str_command = substr($text, $pointer, $closing_position - $pointer);
                        echo $this->getLinkById($link_id,
                            substr($str_command, 1, strpos($str_command, ']') - 1));
                        $pointer += strlen($str_command) + 1; // go to the end of the whole tag construction
                        $this->pointer = $pointer;
                        $sig = '';
                    } elseif (null !== ($positions = $this->nextPartOfCommandOnSameLine($text, ($tag_sig = ']('), $pointer))) {
                        // inline is also fine:
                        $tag_sig_position = $positions[0];
                        $tag_sig_end = $tag_sig_position + strlen($tag_sig);
                        $next_EOL = $positions[1];
                        $pointer++;
                        $end = $this->nextPartOfCommand($text, ')', $tag_sig_end);
                        // TODO check how many is duplicate from ->getLinkById()...
                        if ($end < $next_EOL) {
                            //echo $this->flush();
                            $href = trim(substr($text, $tag_sig_end, $end - $tag_sig_end));
                            if (strlen($href) === 0) {
                                $this->addMessage(sprintf(
                                    __('Incorrect link format at %1$s near %2$s.', 'peatcms'),
                                    $pointer,
                                    substr($text, $pointer - 5, 20)
                                ), 'warn');
                                $end = $next_EOL; // skip it
                            } else {
                                // this may contain a "title" (between double quotes)
                                if (false !== ($pos = strpos($href, '"', 1))) {
                                    // title should end in a double quote, so also remove last character
                                    $title = substr($href, $pos + 1, strlen($href) - $pos - 2);
                                    $href = rtrim(substr($href, 0, $pos));
                                }
                                echo '<a href="';
                                echo $href;
                                if (isset($title)) {
                                    echo '" title="';
                                    echo htmlspecialchars($title);
                                }
                                echo '">';
                                // @since 0.6.17 other tags must be possible inside a link text
                                echo (new Parser($this->logger))->parse(substr($text, $pointer, $tag_sig_position - $pointer), true);
                                echo '</a>';
                            }
                            $end++;
                            $this->pointer = $end;
                            $sig = '';
                        } else {
                            $this->addMessage(sprintf(
                                __('Incorrect link format at %1$s near %2$s.', 'peatcms'),
                                $pointer,
                                substr($text, $pointer - 10, 20)
                            ), 'warn');
                        }
                    } else {
                        $this->addMessage(sprintf(
                            __('Incorrect link format at %1$s near %2$s.', 'peatcms'),
                            $pointer,
                            substr($text, $pointer - 10, 20)
                        ), 'warn');
                    }
                }
            } elseif ('td' === $tag) {
                if (' |' !== $sig) { // do not bother if this is the last on the row
                    if ('td' === $this->getOpenTag()) {
                        echo $this->closeTag();
                        echo $this->openTag('td');
                    }
                    if ('th' === $this->getOpenTag()) {
                        echo $this->closeTag();
                        echo $this->openTag('th');
                    }
                }
            } elseif ($tag === $this->getOpenTag()) { // close the current tag
                echo $this->closeTag();
            } else {
                $this->echoParagraphWhenNecessary();
                echo $this->openTag($tag, $cmd);
            }
        } else { // these are tags that follow a newline character
            $this->holding_eol = false; // the last enter is part of the command, not of the contents
            // h#, hr, pre, TAB, ol, ul, tr, blockquote
            if (in_array($tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'))) {
                echo $this->flush();
                echo $this->closeTags();
                echo $this->openTag($tag);
            } elseif (in_array($tag, array('ul', 'ol'))) {
                echo $this->flush();
                if ('li' !== $this->getOpenTag()) { // if there's no list item yet, start a new list
                    echo $this->closeTags();
                    if (($ol_start = (int)$cmd['signature'])) $cmd['start'] = $ol_start;
                    echo $this->openTag($tag, $cmd);
                } else {
                    echo $this->closeTag();
                }
                echo $this->openTag('li');
            } elseif ('div' === $tag) {
                echo $this->flush();
                echo $this->closeTags(true);
                $next_EOL = strpos($text, "\n", $this->pointer);
                if (3 > $next_EOL - $this->pointer) { // no classes
                    echo $this->openTag('div');
                } else {
                    $classes = trim(substr($text, $this->pointer, $next_EOL - $this->pointer), '§ ');
                    if ('<' !== $classes) { // ‘<’ is the end-div character / ‘§<’ the sequence
                        echo $this->openTag('div', array('className' => $classes));
                    }
                }
                $this->pointer = $next_EOL + 1; // skip the \n
                $sig = ''; // set signature to 0 chars to have the pointer remain at the set position
            } elseif ('tr' === $tag) {
                echo $this->flush();
                if (false === $this->hasOpenTag('table')) {
                    echo $this->closeTags();
                    echo $this->openTag('table');
                } elseif (in_array($this->getOpenTag(), array('td', 'th'))) { // from the previous line
                    echo $this->closeTag();
                }
                if ('tr' === $this->getOpenTag()) {
                    echo $this->closeTag();
                }
                $next_EOL = strpos($text, "\n", $this->pointer);
                // if this is the '| --- row do nothing
                if (strpos($text, '| ---', $this->pointer) === $this->pointer) {
                    $this->pointer = $next_EOL + 1;
                    $sig = ''; // set signature to 0 chars to have the pointer remain at the set position
                } else {
                    echo $this->openTag('tr');
                    // check if | --- is the next row, then we need a table header
                    if (false !== $next_EOL && strpos($text, '| ---', $this->pointer) === $next_EOL + 1) {
                        echo $this->openTag('th');
                    } else {
                        echo $this->openTag('td');
                    }
                }
            } elseif ('hr' === $tag) {
                echo $this->flush();
                echo $this->closeTags();
                echo '<hr';
                if (isset($cmd['className'])) {
                    echo ' class="';
                    echo $cmd['className'];
                    echo '"';
                }
                echo '/>';
                // with HR forget about the rest of this row (note there is always "\n" at the end of $text)
                $this->pointer = strpos($text, "\n", $this->pointer + 1);
                $sig = ''; // set signature to 0 chars to have the pointer remain at the set position
            } elseif ($tag === 'blockquote') {
                if ($tag !== $this->getOpenTag()) {
                    echo $this->flush();
                    echo $this->closeTags();
                    echo $this->openTag($tag);
                } else {
                    echo '<br/>';
                }
            }
        }
        $html = ob_get_clean();
        // set pointers
        $this->pointer += strlen($sig);
        // consume spaces as well before header text
        if (true === $cmd['allow_space'] && $this->pointer < $this->len) { // you only have to check once because the text ends with \n (which is not ' ')
            while (' ' === $text[$this->pointer]
                && '|' !== $text[$this->pointer + 1] // exception for ` | ` where the leading space is part of the tag
            ) {
                $this->pointer++;
            }
        }
        $this->slacker = $this->pointer;
        // memory freeing
        unset($text);

        return $html;
    }

    private function getNextCommand(): ?array
    {
        $text = $this->text;
        while ($this->pointer < $this->len) {
            if (false === $this->escaped) {
                $pointer = $this->pointer;
                $this->log("$pointer: $text[$pointer]");
                foreach ($this->commands as $sig => $command) {
                    if ($sig === substr($text, $pointer, strlen($sig))) { // found this command
                        if ('ol' === $command['tag']) {
                            // this is a special case, no other tag is complicated in this manner...
                            if (false !== ($line_start = strrpos($text, "\n", $pointer - $this->len))) {
                                $line_start++;
                                $substr = substr($text, $line_start, $pointer - $line_start);
                                if (is_numeric($substr)) {
                                    //if ((int)$substr > 0) {
                                    $this->log("<strong>found sig</strong>: $substr at: $this->pointer");
                                    $this->pointer = $line_start;
                                    unset ($text);

                                    return array_merge($command, array('signature' => $substr . '. '));
                                }
                                continue;
                            }
                        }
                        if (false === $command['new_line'] || "\n" === $text[$pointer - 1]) {
                            // also check if the tags are in the right position when required by allow_space
                            if (false === $command['allow_space']) {
                                if ($command['tag'] === $this->getOpenTag()) {
                                    $you_may = $text[$pointer - 1] !== ' ';
                                } else {
                                    $sig_len = strlen($sig);
                                    $you_may = ($pointer + $sig_len < $this->len && $text[$pointer + $sig_len] !== ' ');
                                }
                            } else {
                                $you_may = true;
                            }
                            if ($you_may) {
                                $this->log('<strong>found sig</strong>: ' . var_export($sig, true) . " at: $this->pointer");
                                unset ($text);

                                return array_merge($command, array('signature' => $sig));
                            }
                        }
                    }
                }
            } else {
                $this->escaped = false;
                //$this->pointer++; // character is removed from $text, so don't go forward
            }
            if ('\\' === $text[$this->pointer]) {
                // TODO very dangerous to have characters disappear from the original $text...
                $this->escaped = true;
                // remove the character and run the while again, which effectively skips over the escaped character
                $text = substr_replace($text, '', $this->pointer, 1);
                // correctly update instance values handling the text
                $this->text = $text;
                $this->len = strlen($text);
            }
            $this->pointer++;
        }
        unset ($text);

        return null;
    }

    /**
     * Searches the text for the specified id ([id]:) and returns the complete a-tag for it using link_text as the
     * clickable text
     * when not found it returns the string it received as id.
     * @param string $id the id of the link as should be present in the current text
     * @param string $link_text the text that should be made clickable
     * @return string complete a-tag
     */
    private function getLinkById(string $id, string $link_text): string
    {
        //looks for Markdown link by index somewhere (anywhere) in $text
        $text = $this->text;
        $needle = "\n[$id]:";
        $pos = strpos($text, $needle);
        if (false === $pos) {
            $this->addMessage(sprintf(__('No link found for id %s.', 'peatcms'), $id), 'warn');
            $link = $id;
        } else {
            $pos += strlen($needle);
            $end = $this->nextPartOfCommand($text, ' "', $pos);
            if ($end !== -1) { // check if there is a title for the link
                $href = trim(substr($text, $pos, $end - $pos));
                if (true === str_contains($href, ' ')) { // a link cannot contain spaces, so this " is from something else
                    $end = strpos($text, "\n", $pos);
                    $href = trim(substr($text, $pos, $end - $pos));
                } else {
                    $title_pos = $end + 2;
                    $title_end = $this->nextPartOfCommand($text, '"', $title_pos);
                    if ($title_end !== -1 && $title_end < strpos($text, "\n", $title_pos)) { // titles must be on one line
                        $title = htmlspecialchars(substr($text, $title_pos, $title_end - $title_pos));
                    }
                }
            } else {
                $end = strpos($text, "\n", $pos);
                $href = trim(substr($text, $pos, $end - $pos));
            }
            if ($href[0] === '<') { // markdown allows to surround a link with <> even here, now remove those
                $href = substr($href, 1, strlen($href) - 2); // assume > at the end as well, no double checking
            }
            // @since 0.6.17 tags inside a link are also possible
            $link_text = (new Parser($this->logger))->parse($link_text, true);
            if (isset($title)) {
                $link = "<a href=\"$href\" title=\"$title\">$link_text</a>";
            } else {
                $link = "<a href=\"$href\">$link_text</a>";
            }
        }
        unset($text);

        return $link;
    }

    /**
     * returns the current open tag / deepest level as string, or null when none is open
     * @return string|null
     */
    private function getOpenTag(): ?string
    {
        $tags = $this->open_tags;
        if (($count = count($tags)) > 0) {
            return $tags[$count - 1];
        } else {
            return null;
        }
    }

    private function hasOpenTag(string $tag): bool
    {
        return in_array($tag, $this->open_tags);
    }

    /**
     * Opens a html tag and returns the html as string needed for that
     * TODO do not restrict the attributes...
     * attributes ‘className’ (string) and ‘start’ can be passed in the named array and will be added to the tag
     * @param string $tag the tag to open, e.g. p, span, etc.
     * @param array $attributes array holding attributes, currently only ‘className’ and ‘start’ are allowed
     * @return string the html to open the tag
     */
    private function openTag(string $tag, array $attributes = []): string
    {
        $this->open_tags[] = $tag;
        $this->log("open tag: &lt;$tag&gt;");
        ob_start();
        if (true === $this->holding_eol) {
            $open_tag = $this->getOpenTag();
            if (null !== $open_tag) {
                if ('p' !== $open_tag) echo '<br/>';
                $this->holding_eol = false;
            }
        }
        echo '<';
        echo $tag;
        if (true === isset($attributes['className'])) {
            echo ' class="';
            echo $attributes['className'];
            echo '"';
        }
        if (true === isset($attributes['start'])) {
            echo ' start="';
            echo $attributes['start'];
            echo '"';
        }
        echo '>';

        return ob_get_clean();
    }

    /**
     * Closes the current tag and returns the html (can only close the last tag / deepest level obviously)
     * @return string
     */
    private function closeTag(): string
    {
        if (null !== $this->getOpenTag()) {
            $open_tag = array_pop($this->open_tags);
            return "</$open_tag>";
        }
        $this->addError(__('closeTag called while no tags were open.', 'peatcms'));

        return '';
    }

    /**
     * Closes all tags currently open and returns the appropriate html for that
     * @param bool $includingDiv default false, when true also closes the custom outer div tag when present
     * @return string the html to close the tags
     */
    private function closeTags(bool $includingDiv = false): string
    {
        $open = $this->open_tags;
        if (false === isset($open[0])) return '';

        $str = '';
        if (false === $includingDiv && 'div' === $open[0]) {
            $this->open_tags = array(array_shift($open));
        } else {
            $this->open_tags = array();
        }
        foreach ($open as $index => $tag) {
            $str = "</$tag>$str";
        }

        return $str;
    }

    /**
     * mimics strpos, but checks if the character is not preceeded by \ at the found position
     * returns -1 if the $needle is not found (after $position)
     *
     * @param string $haystack
     * @param string $needle
     * @param int $position from where to start searching for $needle in $haystack, default 0
     * @return int position the $needle is found at, -1 when not found
     */
    private function nextPartOfCommand(string $haystack, string $needle, int $position = 0): int
    {
        if (false === ($found_position = strpos($haystack, $needle, $position))) return -1;
        while ($haystack[$found_position - 1] === '\\') { // the tag is escaped
            $found_position = strpos($haystack, $needle, $found_position + 1);
            if (false === $found_position) return -1;
        }

        return $found_position;
    }

    /**
     * same as ->nextPartOfCommand but with the added condition that the tag must be on the line where $position starts
     * @param string $haystack
     * @param string $needle
     * @param int $position
     * @return array|null
     */
    private function nextPartOfCommandOnSameLine(string $haystack, string $needle, int $position = 0): ?array
    {
        $next_EOL = strpos($haystack, "\n", $position);
        if (($found_position = $this->nextPartOfCommand($haystack, $needle, $position)) !== -1 and
            $found_position < $next_EOL) {
            return array($found_position, $next_EOL);
        } else {
            return null;
        }
    }

    /**
     * returns content that has not been output up until the pointer, resets the slacker to reflect this
     * it converts the string inside pre and code
     * it opens a ‘p’ tag if there’s no tag open to prevent #textnodes
     * it takes care of holding EOL and trailing characters for headings
     * @return string the contents currently between pointer and slacker
     */
    private function flush(): string
    {
        $open_tag = $this->getOpenTag();
        if ('div' === $open_tag) $open_tag = null; // outer div is considered normal page root
        if ($this->pointer > $this->slacker) {
            $str = substr($this->text, $this->slacker, $this->pointer - $this->slacker);
            $this->slacker = $this->pointer;
            if ('pre' === $open_tag || 'code' === $open_tag) {
                // NOTICE: allow original double quotes, but encode all other html chars
                $str = str_replace('&quot;', '"', htmlspecialchars($str));
            }
            if (true === $this->holding_eol) {
                // EOL converts to '<br/>' inside another tag which is not PRE
                if (null !== $open_tag) {
                    $str = "<br/>$str";
                }
                $this->holding_eol = false;
            }
            // check if the line is blank, if not check if it is currently in an open tag
            if ('' !== str_replace(array("\n", "\t", ' '), '', $str)) {
                if (null === $open_tag && false === $this->is_sub_parser) {
                    $str = $this->openTag('p') . $str; // default to regular paragraph
                } elseif (in_array($open_tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'))) {
                    // remove trailing # characters
                    for ($i = strlen($str) - 1; $i > 0; --$i) {
                        if ('#' !== $str[$i]) {
                            $str = substr($str, 0, $i + 1);
                            break;
                        }
                    }
                }
            }
            $this->log("<em>flushing:</em> '$str'");

            return $str;
        }
        $this->log('<em>nothing to flush</em>');

        return '';
    }
}