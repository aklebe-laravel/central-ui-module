<?php

namespace Modules\CentralUi\app\Models;

use Modules\SystemBase\app\Models\ModelWithTags;

/**
 *
 */
class Saying extends ModelWithTags
{

    /**
     * @var array
     */
    protected $guarded = [];

    /**
     * @param  array  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }

    public static function getLikenessItems(int $levenshteinDistance = 10): array
    {
        $allZitate = self::all();
        $similarPairs = [];
        $similarLastIds = [];

        foreach ($allZitate as $zitat) {
            foreach ($allZitate as $comparison) {
                if ($zitat->id >= $comparison->id) {
                    continue;
                }

                // Berechnet die Ähnlichkeit (0 = identisch)
                $distance = levenshtein($zitat->content, $comparison->content);

                // Schwellenwert: z.B. weniger als 10 Zeichen Unterschied
                if ($distance < $levenshteinDistance) {
                    $similarPairs[] = [
                        'id1'   => $zitat->id,
                        'id2'   => $comparison->id,
                        'text1' => $zitat->content,
                        'text2' => $comparison->content,
                        'diff'  => $distance,
                    ];
                    $similarLastIds[] = $comparison->id;
                }
            }
        }

        return ['data' => $similarPairs, 'ids' => implode(',', $similarLastIds)];
    }
}