<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

class UiTranslationTest extends TestCase
{
    public function test_swedish_json_translates_common_ui_labels(): void
    {
        App::setLocale('sv');

        $this->assertSame('Evenemang', __('Services'));
        $this->assertSame('Evenemangsordning', __('Service order'));
        $this->assertSame('Användare', __('Users'));
        $this->assertSame('Moduler', __('Modules'));
        $this->assertSame('Mitt schema', __('My schedule'));
        $this->assertSame('Ladda ner kalender', __('Download calendar'));
        $this->assertSame('Exportera kalkylblad', __('Export spreadsheet'));
        $this->assertSame('Utkast', __('Draft plan'));
        $this->assertSame('Ändrad sedan delning', __('Changed since shared'));
        $this->assertSame('Bjud in personer', __('Invite people'));
        $this->assertSame('Kom igång', __('Getting started'));
        $this->assertSame('Mottagare', __('Recipients'));
    }

    public function test_arabic_json_translates_common_ui_labels(): void
    {
        App::setLocale('ar');

        $this->assertSame('الخدمات', __('Services'));
        $this->assertSame('ترتيب الخدمة', __('Service order'));
        $this->assertSame('المستخدمون', __('Users'));
        $this->assertSame('جدولي', __('My schedule'));
        $this->assertSame('تنزيل التقويم', __('Download calendar'));
        $this->assertSame('مسودة الخطة', __('Draft plan'));
        $this->assertSame('دعوة أشخاص', __('Invite people'));
        $this->assertSame('المستلمون', __('Recipients'));
        $this->assertSame('إشعار الأشخاص', __('Notify people'));
    }

    public function test_english_falls_back_to_key(): void
    {
        App::setLocale('en');

        $this->assertSame('Services', __('Services'));
        $this->assertSame('Service order', __('Service order'));
    }

    public function test_locale_json_files_share_the_same_keys(): void
    {
        $en = $this->jsonKeys('en');
        $sv = $this->jsonKeys('sv');
        $ar = $this->jsonKeys('ar');

        $this->assertSame([], array_values(array_diff($en, $sv)), 'Swedish is missing keys present in English');
        $this->assertSame([], array_values(array_diff($en, $ar)), 'Arabic is missing keys present in English');
        $this->assertSame([], array_values(array_diff($sv, $en)), 'Swedish has orphan keys not in English');
        $this->assertSame([], array_values(array_diff($ar, $en)), 'Arabic has orphan keys not in English');
    }

    /**
     * @return list<string>
     */
    protected function jsonKeys(string $locale): array
    {
        $path = lang_path($locale.'.json');
        $this->assertFileExists($path);

        /** @var array<string, string> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $keys = array_keys($data);
        sort($keys);

        return $keys;
    }
}
