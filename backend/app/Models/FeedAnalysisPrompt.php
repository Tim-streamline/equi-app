<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedAnalysisPrompt extends Model
{
    protected $primaryKey = 'type';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public static function defaults(): array
    {
        return [
            'label' => 'Lees het volledige paardenvoeretiket of de geplakte fabrikanttekst. Schrijf alle leesbare tekst letterlijk over in raw_text. Vul productidentiteit, samenstelling, ingrediënten, toevoegingen en analytische bestanddelen uitsluitend uit de bron. Behoud ingrediëntnamen en bronregels in hun oorspronkelijke taal en volgorde. Vul evidence en source met de letterlijke bronregel. Bewaar percentages, eenheden en grenswaarden; zet alleen de decimale komma in numerieke value om naar een punt. Vul ontbrekende waarden niet met nul en reken geen eenheden om. Gebruik basis product uitsluitend bij een expliciete productbasis, dry_matter bij expliciete droge stof, anders unknown. Totaal selenium bewijst geen toevoeging of chemische vorm. Geef confidence high alleen bij volledig duidelijk leesbare gegevens. Gebruik null, unknown of low bij ontbrekende of onzekere informatie en vermeld onleesbare velden in unreadable_fields. Geef geen beoordelingen of scores.',
            'composition' => "Lees de samenstelling en toevoegingen op deze paardenvoerfoto. Schrijf in raw_text alle leesbare tekst letterlijk over, in de oorspronkelijke taal en volgorde. Behoud kopjes, percentages, eenheden en toevoegingscodes. Gebruik [onleesbaar] voor onleesbare delen.\nVul composition_text met de volledige samenstelling én toevoegingen, zonder analytische bestanddelen of marketingtekst. Neem elk letterlijk vermeld ingrediënt en elke toevoeging afzonderlijk op in ingredients, in etiketvolgorde. Neem de exacte naam over zonder vertaling of normalisatie; zet een vermeld percentage of hoeveelheid in amount en de letterlijke bronregel in evidence. Splits samengestelde ingrediënten niet op als dat niet expliciet op het etiket staat.\nConfidence high geldt alleen voor volledig duidelijk leesbare gegevens. Laat onbekende hoeveelheden null. Geef composition_confidence low bij afgesneden, onleesbare of onvolledige tekst. Noteer zulke delen in unreadable_fields. Toegevoegd selenium en de chemische vorm mogen uitsluitend uit expliciete samenstellings- of toevoegingstekst volgen; citeer die tekst. Een vermelding van totaal selenium bewijst geen toevoeging of vorm. Gebruik anders unknown. Laat nutrients leeg; daarvoor is de aparte voedingswaardenanalyse bedoeld.",
            'analysis' => "Lees de analytische bestanddelen en voedingswaarden op deze paardenvoerfoto. Schrijf in raw_text alle leesbare tekst letterlijk over, inclusief kolomkoppen, decimalen, eenheden en voetnoten. Gebruik [onleesbaar] voor onleesbare delen.\nKoppel elke voedingswaarde aan het juiste nutriënt, de juiste kolom en de letterlijk vermelde eenheid. Geef in source de exacte bronregel en relevante kolomkop of voetnoot. Zet een decimale komma in value om naar een punt; verander de hoeveelheid niet en reken eenheden niet om. Bewaar <, >, minimum en maximum met qualifier. Leid nooit een waarde af uit andere waarden en vul ontbrekende waarden niet met nul.\nGebruik basis product alleen als per kg product, zoals gevoerd of een gelijkwaardige aanduiding expliciet vermeld staat. Gebruik dry_matter alleen bij een expliciete droge-stofbasis en anders unknown. Kies confidence low als waarde, kolom of eenheid niet betrouwbaar leesbaar is; gebruik null voor een onleesbare waarde. Noteer ontbrekende of onleesbare informatie in unreadable_fields. Neem alleen nutriënten op waarvoor het schema een code biedt. Laat ingredients leeg en composition_text null. Totaal selenium is een analysewaarde, geen bewijs van toegevoegd selenium.",
            'packaging' => 'Lees de voorkant en productidentiteit op deze paardenvoerverpakking. Schrijf alle leesbare tekst letterlijk over in raw_text. Gebruik [onleesbaar] voor onleesbare delen. Vul brand, name en barcode alleen met letterlijk zichtbare gegevens. Raad nooit een barcode of ontbrekende cijfers. Kies category alleen als het etiket expliciet de voercategorie vermeldt; anders unknown met category_confidence low. Marketingclaims bewijzen geen ingrediënten, voedingswaarden of geschiktheid. Laat ingredients en nutrients leeg en composition_text null. Vermeld onleesbare velden in unreadable_fields.',
        ];
    }
}
