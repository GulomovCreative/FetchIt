<?php

use PHPUnit\Framework\TestCase;

/**
 * Every fetchit_* key the PHP code uses exists in every language, and every
 * language has the same keys.
 */
class LexiconTest extends TestCase
{
    private function entries($language)
    {
        $_lang = [];
        foreach (glob(MODX_CORE_PATH . "components/fetchit/lexicon/{$language}/*.inc.php") as $file) {
            include $file;
        }

        return $_lang;
    }

    private function usedKeys()
    {
        $sources = array_merge(
            glob(MODX_CORE_PATH . 'components/fetchit/model/*.php'),
            glob(MODX_CORE_PATH . 'components/fetchit/elements/*/*.*'),
            [dirname(MODX_CORE_PATH) . '/assets/components/fetchit/action.php']
        );
        $keys = [];
        foreach ($sources as $file) {
            preg_match_all("/['\"%](fetchit_[a-z_]+)/", (string)file_get_contents($file), $matches);
            $keys = array_merge($keys, $matches[1]);
        }

        // Field names that look like keys.
        $fields = [FetchItGuard::TOKEN, FetchItGuard::POW];

        return array_values(array_diff(array_unique($keys), $fields));
    }

    public function languages()
    {
        return [['en'], ['ru']];
    }

    /**
     * @dataProvider languages
     */
    public function testEveryUsedKeyExists($language)
    {
        $keys = $this->usedKeys();
        $this->assertNotEmpty($keys);

        $missing = array_diff($keys, array_keys($this->entries($language)));

        $this->assertSame([], array_values($missing), "Missing in lexicon/{$language}");
    }

    public function testLanguagesHaveTheSameKeys()
    {
        $en = array_keys($this->entries('en'));
        $ru = array_keys($this->entries('ru'));
        sort($en);
        sort($ru);

        $this->assertSame($en, $ru);
    }
}
