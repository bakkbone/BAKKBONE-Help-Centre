<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Services;

use Bakkbone\HelpCentre\Core\App;

final class FilterEngine
{
    public const FIELDS = [
        'email' => 'Sender email', 'name' => 'Sender name', 'domain' => 'Sender domain', 'subject' => 'Subject',
        'body' => 'Message body', 'to' => 'Recipient mailbox (To)', 'cc' => 'CC addresses', 'source' => 'Source (email/web/api/phone)',
        'topic' => 'Help topic name',
    ];

    public const OPS = [
        'contains' => 'contains', 'not_contains' => 'does not contain', 'equals' => 'equals', 'not_equals' => 'does not equal',
        'starts' => 'starts with', 'ends' => 'ends with', 'regex' => 'matches regex', 'not_regex' => 'does not match regex',
    ];

    public const ACTIONS = [
        'reject' => 'Reject ticket (silently discard)',
        'dept_id' => 'Set department', 'topic_id' => 'Set help topic', 'priority_id' => 'Set priority', 'sla_id' => 'Set SLA plan',
        'status_id' => 'Set status', 'staff_id' => 'Assign to agent', 'team_id' => 'Assign to team',
        'disable_autoresponse' => 'Disable auto-response',
    ];

    /** @param array<string,string> $vars @return array{reject:bool,actions:array<string,mixed>,matched:list<string>} */
    public static function apply(array $vars, string $source): array
    {
        $result = ['reject' => false, 'actions' => [], 'matched' => []];
        $filters = App::db()->all('SELECT * FROM filters WHERE active = 1 ORDER BY sort, id');
        $vars['source'] = $source;
        foreach ($filters as $f) {
            $sources = array_filter(array_map('trim', explode(',', (string)$f['sources'])));
            if ($sources && !in_array($source, $sources, true)) {
                continue;
            }
            $rules = json_decode((string)$f['rules'], true) ?: [];
            if (!$rules || !self::matches($rules, $vars, (bool)$f['match_all'])) {
                continue;
            }
            $result['matched'][] = (string)$f['name'];
            $actions = json_decode((string)$f['actions'], true) ?: [];
            foreach ($actions as $k => $v) {
                if ($v === '' || $v === null || $v === false || $v === 0 || $v === '0') {
                    continue;
                }
                if ($k === 'reject') {
                    $result['reject'] = true;
                    return $result;
                }
                $result['actions'][$k] = $v;
            }
            if ($f['stop_on_match']) {
                break;
            }
        }
        return $result;
    }

    /** @param list<array{field:string,op:string,value:string}> $rules */
    public static function matches(array $rules, array $vars, bool $matchAll): bool
    {
        foreach ($rules as $r) {
            $hit = self::test((string)($vars[$r['field'] ?? ''] ?? ''), (string)($r['op'] ?? 'contains'), (string)($r['value'] ?? ''));
            if ($matchAll && !$hit) {
                return false;
            }
            if (!$matchAll && $hit) {
                return true;
            }
        }
        return $matchAll;
    }

    public static function test(string $subject, string $op, string $value): bool
    {
        $s = mb_strtolower($subject);
        $v = mb_strtolower($value);
        return match ($op) {
            'contains' => $v !== '' && str_contains($s, $v),
            'not_contains' => $v === '' || !str_contains($s, $v),
            'equals' => $s === $v,
            'not_equals' => $s !== $v,
            'starts' => $v !== '' && str_starts_with($s, $v),
            'ends' => $v !== '' && str_ends_with($s, $v),
            'regex' => self::regex($value, $subject),
            'not_regex' => !self::regex($value, $subject),
            default => false,
        };
    }

    private static function regex(string $pattern, string $subject): bool
    {
        if ($pattern === '') {
            return false;
        }
        if (!preg_match('/^([\/#~!@%]).*\1[imsxuU]*$/s', $pattern)) {
            $pattern = '~' . str_replace('~', '\~', $pattern) . '~iu';
        }
        return (bool)@preg_match($pattern, $subject);
    }
}
