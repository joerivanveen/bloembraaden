<?php

declare(strict_types=1);

namespace Bloembraaden;
class Date
{
    public static function getDate( string $value ): ?\DateTimeImmutable {
        // parse the date, it should be YYYY-MM-DD HH:MM:SS.milliseconds+timezone diff compared to UTC
        // ‘O’ means timezone, dates in Bloembraaden generally have the timestamp part present,
        // only user input probably has not
        if ( ( $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s.u O', $value ) ) ) {
            return $dt;
        } elseif ( ( $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s.u O', $value ) ) ) { // official, used by eg Instagram
            return $dt;
        } elseif ( ( $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s O', $value ) ) ) {
            return $dt;
        } elseif ( ( $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s O', $value ) ) ) { // official, used by eg Instagram
            return $dt;
        } else {
            // when no timestamp, this is user input, an instance should be loaded and its timestamp used
            $timezone = new \DateTimeZone( Setup::$timezone );

            if ( ( $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s.u', $value, $timezone ) ) ) {
                return $dt;
            } elseif ( ( $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, $timezone ) ) ) {
                return $dt;
            } elseif ( ( $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $value, $timezone ) ) ) {
                return $dt;
            } elseif ( ( $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', $value, $timezone ) ) ) {
                return $dt->setTime( 12, 0 ); // when no time is given, set in the middle
            }
        }

        //if ($dt === false || array_sum($dt::getLastErrors())) {}
        return null;
    }

    public static function asTime( ?string $str ): string {
        if ( null === $str ) {
            return '00:00';
        }

        $parts = explode( ':', $str );
        $hours = abs( (int) $parts[0] );
        if ( false === isset( $parts[1] ) ) {
            $minutes = '00';
        } else {
            $minutes = abs( (int) $parts[1] );
        }
        $time = array();
        if ( $hours > 23 ) {
            $value = '00:00';
        } else {
            $time[0] = substr( "0$hours", - 2 );
            if ( $minutes > 59 ) {
                $time[1] = '00';
            } else {
                $time[1] = substr( "0$minutes", - 2 );
            }
            $value = implode( ':', $time );
        }

        return $value;
    }

    /**
     * @param int $int
     *
     * @return \DateTimeImmutable
     */
    public static function dateFromInt( int $int ): \DateTimeImmutable {
        // convert to local timezone from utc
        $datetime = ( new \DateTimeImmutable() )->setTimestamp( $int );
        $timezone = new \DateTimeZone( Setup::$timezone );

        // move date to current timezone
        return $datetime->setTimezone( $timezone );
    }

    /**
     * @param string $date_as_string represents a date (+optional time) in appropriate format
     *
     * @return int unix timestamp in seconds from epoch
     */
    public static function intFromDate( string $date_as_string ): int {
        if ( null === ( $date = self::getDate( $date_as_string ) ) ) {
            if ( in_array( $date_as_string, array( 'now', 'now()' ), false ) ) {
                return Setup::getNow();
            } else {
                return 0; // empty or unintelligible means we go to the start of the epoch
            }
        }

        // create an int
        return $date->getTimestamp();
    }
}