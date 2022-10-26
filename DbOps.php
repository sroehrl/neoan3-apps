<?php
/**
 * Created by PhpStorm.
 * User: sroehrl
 * Date: 1/24/2019
 * Time: 11:39 AM
 */

namespace Neoan3\Apps;

/**
 * Class DbOps
 *
 * @package Neoan3\Apps
 */
class DbOps
{
    /**
     * @var array
     */
    private static $preparedExclusions = [];
    /**
     * @var DbEnvironment
     */
    private static $_env;

    /**
     * DbOps constructor.
     *
     * @param $env
     */
    function __construct($env)
    {
        self::$_env = $env;
    }

    /**
     * @return array
     */
    protected function getExclusions()
    {
        return self::$preparedExclusions;
    }

    /**
     * clears Exclusions
     */
    protected function clearExclusions()
    {
        self::$preparedExclusions = [];
    }

    /**
     * @param $value
     * @param string $type
     */
    protected function addExclusion($value, string $type = '')
    {
        if ($type == '') {
            $type = $this->mysqliStmtType($value);
        }
        self::$preparedExclusions[] = $this->prepareBinding($value, $type);
    }

    /**
     * @param $value
     * @param $type
     *
     * @return array
     */
    private function prepareBinding($value, $type): array
    {
        return ['type' => $type, 'value' => $value];
    }

    /**
     * EASY markup interpretation to influence conditions-behavior
     *
     * @param      $input
     * @param bool $set
     * @param bool $prepared
     *
     * @return mixed
     * @throws DbException
     */
    protected function operandi($input, bool $set, bool $prepared)
    {
        switch (gettype($input)){
            case 'integer':
                return $input;
            case 'NULL':
                return $this->setNull($input, $set);
        }
        if (empty($input)) {
            return $this->setNull($input, $set);
        }
        $firstLetter = strtolower(substr($input, 0, 1));
        switch ($firstLetter) {
            case '=':
                // important! this is the first rule and needs to stay as such!
                $return = ' = ? ';
                $this->addExclusion(substr($input, 1));
                break;
            case '>':
            case '<':
                $return = ' ' . $firstLetter . ' "' . intval(substr($input, 1)) . '"';
                break;
            case '.':
                $return = ' = NOW()';
                break;
            case '$':
                $rest = substr($input, 1);
                if ($prepared) {
                    $return = ' = UNHEX(?)';
                    $this->addExclusion($rest, 's');
                } else {
                    $return = ' = UNHEX("' . $rest . '")';
                }
                break;
            case '!':
                if (strtolower($input) == '!null' || strlen($input) == 1) {
                    if ($set) {
                        $this->formatError([$input],
                            'Cannot set "NOT NULL" as value for "' . substr($input, 1) . '"');
                    }
                    $return = ' IS NOT NULL ';
                } else {
                    if ($set) {
                        $this->formatError([$input], 'Cannot use "!= ' . substr($input, 1) . '" to set a value');
                    }
                    $return = ' != "' . substr($input, 1) . '"';
                }
                break;
            case '{':
                $return = ' ' . substr($input, 1, -1);
                break;
            case '^':
                $return = ($set ? ' = NULL' : ' IS NULL');
                break;
            default:
                if (strtolower($input) == 'null') {
                    $return = ($set ? ' = NULL' : ' IS NULL');
                } elseif ($prepared) {
                    $return = preg_match('/%/', $input) ? ' LIKE ? ' : ' = ? ';
                    $this->addExclusion($input);
                } else {
                    $return = ' = "' . $input . '"';
                }
                break;
        }
        return $return;
    }

    protected function setNull($value, bool $set): string
    {
        return ($set ? ' = NULL' : ' IS NULL');
    }

    /**
     * EASY markup interpretation to influence select-behavior
     *
     * @param $string
     *
     * @return string
     */
    protected function selectandi($string)
    {
        $firstLetter = strtolower(substr($string, 0, 1));
        $rest = substr($string, 1);
        switch ($firstLetter) {
            case '=':
                $return = $this->addBackticks(substr($string, 1));
                break;
            case '#':
                $return = 'UNIX_TIMESTAMP(' . $this->_sanitizeAndAddBackticks($this->cleanAs($rest)) . ')*1000';
                break;
            case '$':
                $return = 'HEX(' . $this->_sanitizeAndAddBackticks($this->cleanAs($rest)) . ')';
                break;
            default:
                $return = $this->addBackticks(preg_replace('/\:.*/', '', $string));
        }
        return $return . $this->checkAs($string);
    }

    /**
     * Shorthand for sanitizing and adding backticks
     *
     * @param $string
     *
     * @return string
     */
    private function _sanitizeAndAddBackticks($string)
    {
        return $this->addBackticks(Db::sanitizeKey($string));
    }

    /**
     * Adds backticks to keys, tables & columns
     *
     * @param $string
     *
     * @return string
     */
    protected function addBackticks($string)
    {
        $parts = explode('.', $string);
        $result = '';
        foreach ($parts as $i => $part) {
            $result .= ($i > 0 ? '.' : '');
            if ($part !== '*') {
                $result .= '`' . $part . '`';
            } else {
                $result .= $part;
            }
        }
        return $result;
    }

    /**
     * @param $rest
     *
     * @return string
     */
    protected function checkAs($rest)
    {
        if (empty($rest) || $rest == '' || strpos($rest, '*') !== false) {
            // catch asterisk-selector
            return '';
        }
        $as = explode(':', $rest);
        $als = explode('.', $rest);
        if (count($as) > 1) {
            return ' as "' . $as[1] . '"';
        } elseif (count($als) > 1) {
            return ' as "' . $als[1] . '"';
        } else {

            return ' as "' . preg_replace('/[^a-zA-Z_]/', '', $rest) . '"';
        }
    }

    /**
     * @param $rest
     *
     * @return mixed
     */
    protected function cleanAs($rest)
    {
        $as = explode(':', $rest);
        $als = explode('.', $rest);
        if (count($as) > 1) {
            return $as[0];
        } elseif (count($als) > 1) {
            return $als[0];
        } else {
            return $rest;
        }
    }


    /**
     * @param      $values
     * @param      $msg
     * @param bool $sql
     *
     * @throws DbException
     */
    public function formatError($values, $msg, $sql = false)
    {
        $format = $msg . ' Given values: ' . implode(', ', $values);
        if (self::$_env->get('dev_errors') && $sql) {
            $format .= ' SQL: ' . $sql;
        }

        throw new DbException($format);
    }

    /**
     * @param $value
     *
     * @return string
     */
    public function mysqliStmtType($value)
    {
        $value = trim($value);
        if (!preg_match('/[^0-9\.]/', $value) && substr($value, 0, 1) != 0) {
            // d or i?
            return ctype_digit($value) ? 'i' : 'd';
        }
        return 's';
    }

    /**
     * @param $str
     *
     * @return bool
     */
    public static function isBinary($str)
    {
//         return preg_match('~[^\x20-\x7E\t\r\n]~', $str) > 0;
        return !preg_match('//u', $str);
    }
}
