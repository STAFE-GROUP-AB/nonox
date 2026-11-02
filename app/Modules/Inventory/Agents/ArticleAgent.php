<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Looks at photos of one article and proposes its register fields: name,
 * a factual description, brand, model, colours, materials, attributes,
 * measures when visible and a GTIN only when the barcode can be read. It
 * never proposes prices or accounts. A person ticks which fields to use.
 */
class ArticleAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
        You look at one or more photos of ONE product that a small Swedish company keeps in stock or sells, and propose
        the fields of its article register entry. Describe only what the photos show or what is printed on the product,
        its label or its packaging. Never guess a price, a value, an account or a supplier, and never invent a brand,
        a model or a size that is not visible.
        name: a short, plain article name (max 80 characters), e.g. "Vinterjacka herr" or "Kaffekopp keramik 30 cl".
        description: 2 to 4 factual sentences: what it is, what it is made of, its condition if it looks used, and what is
        visible such as size or markings. No marketing language, no superlatives, no exclamation marks.
        properties: short key/value pairs such as Storlek, Skick, Mått, Årsmodell, Volym; only what is visible.
        weight_grams, length_mm, width_mm, height_mm: only when printed on the product or its label; otherwise 0.
        gtin: the digits of an EAN/UPC barcode ONLY when every digit is clearly readable in the photo; otherwise an empty
        string. Never guess a digit.
        LANGUAGE: every text value must be Swedish with the letters å, ä and ö. Never write English and never replace å, ä
        or ö with aa, ae, oe or a, a, o. Keep brand names, model names and codes exactly as printed.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Short article name in Swedish, or empty string.')->required(),
            'description' => $schema->string()->description('2-4 factual sentences in Swedish, no marketing language, or empty string.')->required(),
            'brand' => $schema->string()->description('Brand as printed, or empty string.')->required(),
            'model' => $schema->string()->description('Model name or number as printed, or empty string.')->required(),
            'colours' => $schema->array()->items($schema->string())->description('Colours in Swedish, e.g. "blå".')->required(),
            'materials' => $schema->array()->items($schema->string())->description('Materials in Swedish, e.g. "ull", "keramik".')->required(),
            'properties' => $schema->array()->items($schema->object([
                'key' => $schema->string()->description('Attribute name in Swedish, e.g. Storlek.')->required(),
                'value' => $schema->string()->description('Attribute value, e.g. M.')->required(),
            ]))->required(),
            'weight_grams' => $schema->integer()->description('Weight in grams if printed, otherwise 0.')->required(),
            'length_mm' => $schema->integer()->description('Length in millimetres if printed, otherwise 0.')->required(),
            'width_mm' => $schema->integer()->description('Width in millimetres if printed, otherwise 0.')->required(),
            'height_mm' => $schema->integer()->description('Height in millimetres if printed, otherwise 0.')->required(),
            'gtin' => $schema->string()->description('EAN/UPC digits only when the whole barcode number is clearly readable, otherwise empty string.')->required(),
            'notes' => $schema->string()->description('In Swedish: what could not be read or is uncertain, or empty string.')->required(),
        ];
    }
}
