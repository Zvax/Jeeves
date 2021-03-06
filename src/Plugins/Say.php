<?php declare(strict_types = 1);

namespace Room11\Jeeves\Plugins;

use Room11\Jeeves\Chat\Command;
use Room11\Jeeves\Exception;
use Room11\Jeeves\System\PluginCommandEndpoint;
use Room11\StackChat\Client\Client as ChatClient;
use Room11\StackChat\Client\PostFlags;
use Room11\StackChat\Client\TextFormatter;
use Room11\StackChat\Room\Room as ChatRoom;

class InvalidMessageFormatException extends Exception {}

class Say extends BasePlugin
{
    private $chatClient;
    private $textFormatter;

    public function __construct(ChatClient $chatClient, TextFormatter $textFormatter)
    {
        $this->chatClient = $chatClient;
        $this->textFormatter = $textFormatter;
    }

    public function say(Command $command)
    {
        return $this->chatClient->postMessage($command, $this->getMessageResponse($command));
    }

    public function sayf(Command $command)
    {
        try {
            return $this->chatClient->postMessage(
                $command,
                yield from $this->getFormattedMessageResponse($command),
                PostFlags::ALLOW_PINGS
            );
        } catch (InvalidMessageFormatException $e) {
            return $this->chatClient->postReply($command, $e->getMessage());
        }
    }

    public function reply(Command $command)
    {
        return $this->chatClient->postReply($command, $this->getMessageResponse($command));
    }

    public function replyf(Command $command)
    {
        try {
            return $this->chatClient->postReply(
                $command,
                yield from $this->getFormattedMessageResponse($command),
                PostFlags::ALLOW_PINGS
            );
        } catch (InvalidMessageFormatException $e) {
            return $this->chatClient->postReply($command, $e->getMessage());
        }
    }

    private function getMessageResponse(Command $command): string
    {
        return $this->textFormatter->interpolateEscapeSequences(implode(' ', $command->getParameters()));
    }

    private function getFormattedMessageResponse(Command $command)
    {
        $current = '';
        $components = [];

        foreach ($command->getParameters() as $parameter) {
            if ($parameter !== '/') {
                $current .= str_replace('\\/', '/', $parameter) . ' ';
            } else if ($current !== '') {
                $components[] = trim($current);
                $current = '';
            }
        }

        if ($current !== '') {
            $components[] = trim($current);
        }

        try {
            list($string, $args) = yield from $this->preProcessFormatSpecifiers($command->getRoom(), $components);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidMessageFormatException('Only if you say it first');
        }

        $string = $this->textFormatter->stripPingsFromText($string);
        $string = $this->textFormatter->interpolateEscapeSequences($string);

        if (false === $result = @\vsprintf($string, $args)) {
            throw new InvalidMessageFormatException('printf() failed, check your format string and arguments');
        }

        return $result;
    }

    private function preProcessFormatSpecifiers(ChatRoom $room, array $components)
    {
        static $expr = /** @lang RegExp */ "/
          %
          (?:([0-9]+)\\$)? # position
          ([-+])?          # sign
          (\\x20|0|'.)?    # padding char
          (-)?             # alignment
          ([0-9]+)?        # padding width
          (\\.[0-9]*)?     # precision
          (.)              # type
        /x";

        $string = $components[0];
        $args = array_slice($components, 1);

        if (0 === $count = preg_match_all($expr, $string, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [$string, $args];
        }

        foreach ($matches as $i => $match) {
            if (($match[5][0] !== '' && ((int)$match[5][0]) > TextFormatter::TRUNCATION_LIMIT)
                || ($match[6][0] !== '' && ((int)substr($match[6][0], 1)) > TextFormatter::TRUNCATION_LIMIT)) {
                throw new \InvalidArgumentException;
            }

            if ($match[7][0] !== 'p') {
                continue;
            }

            $string = substr_replace($string, 's', $match[7][1], 1);
            $argIndex = $match[1][0] !== ''
                ? $match[1][0] - 1
                : $i;

            if (!isset($args[$argIndex])) {
                continue;
            }

            if ($args[$argIndex][0] === '@') {
                $args[$argIndex] = $this->textFormatter->stripPingsFromText($args[$argIndex]);
            } else if (null !== $pingable = yield $this->chatClient->getPingableName($room, $args[$argIndex])) {
                $args[$argIndex] = '@' . $pingable;
            }
        }

        return [$string, $args];
    }

    public function getDescription(): string
    {
        return 'Mindlessly parrots whatever crap you want';
    }

    public function getCommandEndpoints(): array
    {
        return [
            new PluginCommandEndpoint('say', [$this, 'say'], 'say'),
            new PluginCommandEndpoint('sayf', [$this, 'sayf'], 'sayf', 'Same as say with printf-style formatting, separate format string and args with / slashes'),
            new PluginCommandEndpoint('reply', [$this, 'reply'], 'reply', 'Same as say except it replies to the invoking message'),
            new PluginCommandEndpoint('replyf', [$this, 'replyf'], 'replyf', 'Same as sayf except it replies to the invoking message'),
        ];
    }
}
