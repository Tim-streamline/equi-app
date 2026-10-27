<?php

use App\Models\IntakeField;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const LEGACY = 'Nee / vaak nat, modderig of oncomfortabel';

    public function up(): void
    {
        // Cross-section conditions read stored answers as they were given, so an owner who
        // answered the old wording of "buiten-droog-liggen" must still see the shelter question.
        IntakeField::where('key', 'schuilplaats-inrichting')->get()->each(function (IntakeField $field) {
            $showIf = $field->show_if;
            foreach ($showIf['$any'] ?? [] as $i => $condition) {
                $values = $condition['huisvesting.buiten-droog-liggen'] ?? null;
                if (is_array($values) && in_array('Nee', $values, true) && ! in_array(self::LEGACY, $values, true)) {
                    $showIf['$any'][$i]['huisvesting.buiten-droog-liggen'][] = self::LEGACY;
                }
            }
            if ($showIf !== $field->show_if) {
                $field->update(['show_if' => $showIf]);
            }
        });
    }

    public function down(): void
    {
        // Keep the condition; removing it would hide the question again for older answers.
    }
};
