<?php

declare(strict_types=1);

namespace Rank;

final class TimeParser
{
    /**
     * Parse duration string like "1d2h30m15s" to seconds.
     */
    public static function parseDuration(string $input): ?int
    {
        $input = trim($input);
        if($input === ''){
            return null;
        }
        if(ctype_digit($input)){
            return (int) $input; // seconds
        }
        $pattern = '/(\d+)\s*([dhms])/i';
        preg_match_all($pattern, $input, $matches, PREG_SET_ORDER);
        if(empty($matches)){
            return null;
        }
        $total = 0;
        foreach($matches as $m){
            $num = (int) $m[1];
            $unit = strtolower($m[2]);
            switch($unit){
                case 'd': $total += $num * 86400; break;
                case 'h': $total += $num * 3600; break;
                case 'm': $total += $num * 60; break;
                case 's': $total += $num; break;
            }
        }
        return $total;
    }
}
