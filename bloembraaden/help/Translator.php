<?php
function __($text, $domain)
{
    if (isset(\Bloembraaden\Setup::$translations[$text])) return \Bloembraaden\Setup::$translations[$text];
    return $text;
}

// https://stackoverflow.com/questions/1949162/how-could-i-parse-gettext-mo-files-in-php4-without-relying-on-setlocale-locales


class MoParser
{
    private bool $_bigEndian = false;
    private $_file;

    private function _readMOData($bytes)
    {
        if ($this->_bigEndian === false) {
            return unpack('V' . $bytes, fread($this->_file, 4 * $bytes));
        } else {
            return unpack('N' . $bytes, fread($this->_file, 4 * $bytes));
        }
    }

    public function loadTranslationData(string $presentation_instance, string $locale): array
    {
        $filename = CORE . "../htdocs/instance/$presentation_instance/$presentation_instance.mo";
        if (false === file_exists($filename) || false === is_readable($filename)) {
            // @since 0.6.16 you can safely run an instance without translations
            return array($locale=>array());
        }
        if (filesize($filename) < 10) {
            Bloembraaden\Help::addError(new Exception('‘' . $filename . '’ is not a gettext file'));
            return array($locale=>array());
        }
        $_data = array();
        $this->_bigEndian = false;
        $this->_file = fopen($filename, 'rb');
        // get Endian
        $input = $this->_readMOData(1);
        if (strtolower(substr(dechex($input[1]), -8)) == '950412de') {
            $this->_bigEndian = false;
        } elseif (strtolower(substr(dechex($input[1]), -8)) == 'de120495') {
            $this->_bigEndian = true;
        } else {
            Bloembraaden\Help::addError(new Exception('‘' . $filename . '’ is not a gettext file'));
            return array($locale=>array());
        }
        // read revision - not supported for now
        $input = $this->_readMOData(1);
        // number of bytes
        $input = $this->_readMOData(1);
        $total = $input[1];
        // number of original strings
        $input = $this->_readMOData(1);
        $OOffset = $input[1];
        // number of translation strings
        $input = $this->_readMOData(1);
        $TOffset = $input[1];
        // fill the original table
        fseek($this->_file, $OOffset);
        $origtemp = $this->_readMOData(2 * $total);
        fseek($this->_file, $TOffset);
        $transtemp = $this->_readMOData(2 * $total);
        for ($count = 0; $count < $total; ++$count) {
            if ($origtemp[$count * 2 + 1] != 0) {
                fseek($this->_file, $origtemp[$count * 2 + 2]);
                $original = @fread($this->_file, $origtemp[$count * 2 + 1]);
                $original = explode("\0", $original);
            } else {
                $original[0] = '';
            }
            if ($transtemp[$count * 2 + 1] != 0) {
                fseek($this->_file, $transtemp[$count * 2 + 2]);
                $translate = fread($this->_file, $transtemp[$count * 2 + 1]);
                $translate = explode("\0", $translate);
                if ((count($original) > 1) && (count($translate) > 1)) {
                    $_data[$locale][$original[0]] = $translate;
                    array_shift($original);
                    foreach ($original as $orig) {
                        $_data[$locale][$orig] = '';
                    }
                } else {
                    $_data[$locale][$original[0]] = $translate[0];
                }
            }
        }
        $_data[$locale][''] = trim($_data[$locale]['']);
        unset($_data[$locale]['']);

        return $_data;
    }
}