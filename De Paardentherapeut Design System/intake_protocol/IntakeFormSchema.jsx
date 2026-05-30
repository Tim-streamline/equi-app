/* global window */
// IntakeFormSchema.jsx, single source of truth voor de Protocol-intake.
// Gebaseerd op Shelley's "Standaard Anamnese beoordelingsformulier" (Google Forms).
// Elke vraag uit dat formulier staat hieronder; daarbij een paar app-specifieke
// uitbreidingen (zoals criticalIf/flagIf voor het auto-protocol).
//
// Veldtypes:
//   text         single-line
//   textarea     multi-line
//   number       numeriek (met unit)
//   radio        single choice
//   multi        meerdere keuzes (chips)
//   slider       1–5 schaal tussen twee uitersten
//   date         datum
//   photo        foto-upload (één of meer)
//   file         document-upload (PDF)
//   repeater     dynamische lijst
//   sectionhead  visuele subsectie-titel
//
// `flagIf`    , antwoord triggert "aandachtspunt" op Shelley's review
// `criticalIf`, antwoord blokkeert auto-start (drachtig/koorts/koliek)
// `protocolIf`, antwoord triggert specifieke add-on/aanpassing in protocol

const INTAKE_FORM_SCHEMA = [

  /* ============= 00 · CONTACT & OPENHEID ============= */
  {
    id: 'contact', nr: 0,
    title: 'Contactgegevens en verwachtingen',
    intro: 'Eerst even hoe ik je kan bereiken, en of je openstaat voor veranderingen.',
    minutes: 2,
    fields: [
      { id: 'email',          label: 'E-mailadres',                            type: 'text', required: true,
        hint: 'Op dit adres stuur ik je protocol en kopie van je antwoorden.' },
      { id: 'naam-eigenaar',  label: 'Jouw naam',                              type: 'text', required: true },
      { id: 'tel-eigenaar',   label: 'Telefoonnummer',                         type: 'text', required: true,
        hint: 'Voor het geval ik je acuut wil bereiken, ik bel je niet zomaar.' },
      { id: 'hoe-gevonden',   label: 'Hoe ben je bij De Paardentherapeut terecht gekomen?', type: 'textarea', required: true },
      { id: 'aanpassingen-openheid', label: 'Sta je open voor aanpassingen in voer- en managementbeleid?', type: 'radio', required: true,
        options: ['Ja, helemaal', 'Ik twijfel nog, leg uit hieronder', 'Anders'],
        hint: 'Eerlijk zijn helpt mij realistisch te zijn.',
        flagIf: ['Ik twijfel nog, leg uit hieronder', 'Anders'] },
      { id: 'aanpassingen-toelichting', label: 'Toelichting',                  type: 'textarea',
        showIf: { 'aanpassingen-openheid': ['Ik twijfel nog, leg uit hieronder', 'Anders'] } },
    ],
  },

  /* ============= 01 · OVER JE PAARD ============= */
  {
    id: 'paard', nr: 1,
    title: 'Je paard in het kort',
    intro: 'De basics, zodat ik weet over wie we het hebben.',
    minutes: 4,
    fields: [
      { id: 'naam',           label: 'Naam van je paard',                      type: 'text',   required: true },
      { id: 'ras',            label: 'Ras of kruising',                        type: 'text',   required: true,
        hint: 'Niet zeker? "Onbekend" mag ook.' },
      { id: 'geboortedatum',  label: 'Geboortedatum (zo precies mogelijk)',    type: 'date',
        hint: 'Niet bekend? Vul leeftijd in jaren in onder.' },
      { id: 'leeftijd',       label: 'Leeftijd',                               type: 'number', unit: 'jaar', required: true },
      { id: 'geslacht',       label: 'Geslacht',                               type: 'radio',  required: true,
        options: ['merrie', 'ruin', 'hengst'] },
      { id: 'gecastreerd',    label: 'Op welke leeftijd gecastreerd?',         type: 'text',
        showIf: { geslacht: 'ruin' } },
      { id: 'drachtig',       label: 'Is ze drachtig of lacterend?',           type: 'radio',
        options: ['nee', 'drachtig', 'lacterend'],
        showIf: { geslacht: 'merrie' },
        flagIf: ['drachtig', 'lacterend'],
        criticalIf: ['drachtig', 'lacterend'],
        protocolIf: { veiligheid: 'Protocol blokkeren, wacht tot dracht/lactatie voorbij' } },
      { id: 'gewicht',        label: 'Geschat gewicht',                        type: 'number', unit: 'kg', required: true,
        hint: 'Belangrijk, alle doseringen in het protocol worden hierop berekend.' },
      { id: 'gewicht-methode',label: 'Hoe is dit gewicht bepaald?',            type: 'radio',  required: true,
        options: ['geschat op zicht', 'gewogen op weegbrug', 'gemeten met gewichts-lint', 'rapport dierenarts'] },
      { id: 'stokmaat',       label: 'Stokmaat',                               type: 'number', unit: 'cm', step: 1, required: true },
      { id: 'stokmaat-methode',label: 'Geschat of gemeten?',                   type: 'radio',
        options: ['geschat', 'gemeten'] },
      { id: 'adres-stal',     label: 'Adres van de stal',                      type: 'textarea', required: true,
        hint: 'Straat + plaats, postcode helpt me bij regio-specifieke tips (bv. bodemtype).' },
      { id: 'sinds-bezit',    label: 'Sinds wanneer is je paard bij jou?',     type: 'text', required: true,
        hint: 'Maand + jaar is genoeg.' },
      { id: 'eerste-eigenaar',label: 'Ben je de eerste eigenaar?',             type: 'radio',
        options: ['ja', 'nee', 'weet ik niet'] },
      { id: 'conditie',       label: 'Hoe zou je de conditie classificeren?',  type: 'radio', required: true,
        options: ['ondergewicht', 'dun', 'slank', 'gespierd / sportief', 'normaal', 'iets te zwaar', 'overgewicht', 'obees'],
        flagIf: ['ondergewicht', 'dun', 'iets te zwaar', 'overgewicht', 'obees'] },
      { id: 'conditie-veranderd', label: 'Is de conditie veranderd sinds je paard bij jou is?', type: 'textarea', required: true,
        hint: 'Zo ja, hoe? Aangekomen, afgevallen, omgekeerd?' },
      { id: 'gezondheid-eigen-woorden', label: 'Hoe omschrijf je de gezondheidsstatus op dit moment?', type: 'textarea', required: true,
        hint: 'In je eigen woorden, geen jargon nodig.' },
      { id: 'foto-paard',     label: 'Foto van je paard (zijaanzicht)',        type: 'photo',
        hint: 'Voor mijn dossier, zo weet ik gelijk met wie ik werk.' },
    ],
  },

  /* ============= 02 · KLACHT & HULPVRAAG ============= */
  {
    id: 'klacht', nr: 2,
    title: 'De klacht en je hulpvraag',
    intro: 'Wat speelt er nu? Wees zo open en gedetailleerd mogelijk, ik lees alles persoonlijk.',
    minutes: 6,
    fields: [
      { id: 'hulpvraag',      label: 'Wat is je klacht of hulpvraag?',         type: 'textarea', required: true },
      { id: 'begonnen-wanneer', label: 'Wanneer is dit begonnen en onder welke omstandigheden?', type: 'textarea', required: true,
        hint: 'Was er een verhuizing, voerwissel, ziekte, seizoenswissel?' },
      { id: 'subklacht',      label: 'Is er een subklacht / hulpvraag 2?',     type: 'textarea',
        hint: 'Optioneel, als er meerdere dingen spelen.' },
      { id: 'wens',           label: 'Wat is je wens van dit traject?',        type: 'textarea', required: true },
      { id: 'acuut',          label: 'Heeft ze NU acute klachten?',            type: 'multi',
        options: ['koorts', 'ernstige kreupelheid', 'koliek', 'wond / verwonding', 'geen'],
        hint: 'Bij acute klachten eerst dierenarts, het traject pauzeert dan.',
        criticalIf: ['koorts', 'ernstige kreupelheid', 'koliek'],
        protocolIf: { veiligheid: 'Protocol blokkeren, los acute klacht eerst op' } },
      { id: 'thema',          label: 'Welke thema\'s spelen mee?',             type: 'multi',
        options: ['Jeuk', 'Darmen', 'Staakgedrag', 'Hoeven', 'Voeding', 'Spierspanning', 'Ademhaling', 'Houding & balans', 'Gedrag', 'Energie', 'Luchtwegen', 'Pees / gewricht'] },
      { id: 'da-behandeling', label: 'Is het paard onder behandeling van een dierenarts?', type: 'radio', required: true,
        options: ['ja, met diagnose', 'ja, in onderzoek', 'nee'] },
      { id: 'da-diagnose',    label: 'Welke diagnose is gesteld?',             type: 'textarea',
        showIf: { 'da-behandeling': ['ja, met diagnose', 'ja, in onderzoek'] },
        flagIf: 'non-empty' },
      { id: 'eerder-behandeld', label: 'Al eerder behandeld door een therapeut voor déze klacht(en)?', type: 'radio', required: true,
        options: ['ja', 'nee'] },
      { id: 'eerder-wat',     label: 'Zo ja, welk type therapeut en wanneer?', type: 'textarea',
        showIf: { 'eerder-behandeld': 'ja' } },
      { id: 'eerder-resultaat',label: 'Met welk resultaat?',                   type: 'textarea',
        showIf: { 'eerder-behandeld': 'ja' } },
      { id: 'huidige-aanpak', label: 'Wat doe je op dit moment aan de hulpvraag?', type: 'textarea',
        hint: 'Therapie, middelen, maatregelen, wat dan ook.' },
      { id: 'bloedonderzoek', label: 'Bloedonderzoek aanwezig?',                type: 'file',
        hint: 'Upload PDF (max 5 bestanden, ieder max 10 MB).' },
      { id: 'foto-historie',  label: 'Foto\'s van de afgelopen 2–5 jaar',       type: 'photo',
        hint: 'Helpt mij om ontwikkeling te zien. Max 10 foto\'s.' },
      { id: 'gedragsveranderingen', label: 'Veranderingen in gedrag recent?',   type: 'textarea',
        flagIf: 'non-empty' },
      { id: 'allergie',       label: 'Bekende allergieën?',                     type: 'text' },
      { id: 'ervaring-holistisch', label: 'Ervaring met holistische therapieën?', type: 'textarea',
        hint: 'Osteopaat, kruidengeneeskunde, etc. Mag ook "geen".' },
      { id: 'stressfactoren', label: 'Stressfactoren of veranderingen recent',  type: 'textarea',
        hint: 'Verhuizing, nieuwe stalgenoten, voerwissel, ander werk, …',
        flagIf: 'non-empty' },
      { id: 'leuk',           label: 'Wat vindt je paard heel leuk?',           type: 'textarea',
        hint: 'Helpt me beeld te krijgen van wat haar gelukkig maakt.' },
    ],
  },

  /* ============= 03 · GESCHIEDENIS, vroege jaren & moeder ============= */
  {
    id: 'geschiedenis', nr: 3,
    title: 'Achtergrond en jeugd',
    intro: 'Hoe je paard groot is geworden, vaak verstopte oorzaken zitten hier.',
    minutes: 5,
    fields: [
      { id: 'moeder-voer-huis', label: 'Hoe werd de moeder gevoerd en gehuisvest?', type: 'textarea',
        hint: 'Weet je niet? "Onbekend" mag.' },
      { id: 'eerste-maanden', label: 'Hoe heeft je paard de eerste maanden doorgebracht?', type: 'textarea',
        hint: 'Traditionele stalling, opfok, weiland, wildgebied, …' },
      { id: 'moeder-metabolisch', label: 'Had de moeder tekenen van metabolische problemen?', type: 'multi',
        options: ['mestwater', 'diarree', 'koliekgevoeligheid', 'zomereczeem', 'mok', 'chronisch hoesten', 'overgewicht', 'EMS', 'PPID', 'hoefbevangenheid', 'onbekend', 'geen'],
        flagIf: ['mestwater', 'diarree', 'koliekgevoeligheid', 'zomereczeem', 'mok', 'chronisch hoesten', 'overgewicht', 'EMS', 'PPID', 'hoefbevangenheid'] },
      { id: 'spenen-inrijden-huis', label: 'Hoe woonde je paard tussen spenen en inrijden?', type: 'textarea',
        hint: 'Kuddegenoten, alleen, op stal, op het land, …' },
      { id: 'spenen-inrijden-voer', label: 'Hoe werd ze gevoerd in die periode?', type: 'textarea',
        hint: 'Ruwvoer verpakt/onverpakt, bijvoeding, …' },
      { id: 'spenen-inrijden-symptomen', label: 'Heeft ze in die periode symptomen / ziektes ontwikkeld?', type: 'textarea',
        hint: 'Welke, wanneer, hoe behandeld?',
        flagIf: 'non-empty' },
      { id: 'medische-geschiedenis-volledig', label: 'Volledige medische geschiedenis, chronologisch', type: 'textarea', required: true,
        hint: 'Ziekte, symptomen, bijzonderheden waarvoor de DA kwam, in chronologische volgorde met (geschatte) data + welke behandelmethodes + wat wel/niet aansloeg.',
        flagIf: 'non-empty' },
    ],
  },

  /* ============= 04 · MEDISCH & SPECIALISTEN ============= */
  {
    id: 'medisch', nr: 4,
    title: 'Medische zorg en specialisten',
    intro: 'Vaccinaties, ontworming, tandarts, hoefsmid, zadelmaker.',
    minutes: 6,
    fields: [

      /* ---- Vaccinaties & ontworming ---- */
      { id: 'sec-vacc',       label: 'Vaccinatie en ontworming',             type: 'sectionhead' },
      { id: 'vaccinaties',    label: 'Welke vaccinaties krijgt het paard?',     type: 'multi',
        options: ['Tetanus', 'Influenza', 'Rhinopneumonie', 'West Nijl', 'Geen', 'Anders'] },
      { id: 'vacc-laatst',    label: 'Wanneer voor het laatst gevaccineerd?',   type: 'date', required: true },
      { id: 'vacc-volgende',  label: 'Volgende vaccinatie staat gepland op',    type: 'text',
        hint: 'Datum of seizoen.' },
      { id: 'ontworming-laatst', label: 'Wanneer voor het laatst ontwormd?',    type: 'date', required: true },
      { id: 'ontworming-middel', label: 'Met welk wormmiddel?',                 type: 'text', required: true },
      { id: 'ontworming-volgende', label: 'Wanneer volgende ontworming?',       type: 'text', required: true },
      { id: 'ontworming-strategie', label: 'Op basis van mestonderzoek of vast schema?', type: 'radio',
        options: ['vast schema', 'mestonderzoek', 'mix'] },

      /* ---- Gebit / tandarts ---- */
      { id: 'sec-tand',       label: 'Gebit en tandarts',                    type: 'sectionhead' },
      { id: 'tandarts-freq',  label: 'Hoe vaak wordt het gebit gecontroleerd?', type: 'radio', required: true,
        options: ['jaarlijks', 'om de 6 maanden', 'op afroep', 'nog nooit'],
        flagIf: ['nog nooit'] },
      { id: 'tandarts-laatst',label: 'Wanneer is de tandarts voor het laatst geweest?', type: 'date', required: true },
      { id: 'tandarts-volgende',label: 'Wanneer komt de tandarts weer?',         type: 'text', required: true },
      { id: 'tandarts-methode', label: 'Hoe wordt het gebit behandeld?',         type: 'radio', required: true,
        options: ['handmatig zonder verdoving', 'handmatig met verdoving', 'elektrisch zonder verdoving', 'elektrisch met verdoving', 'mix / weet ik niet'] },
      { id: 'tandarts-bijz',  label: 'Bijzonderheden bij laatste tandartsbezoek?', type: 'textarea', required: true,
        flagIf: 'non-empty' },

      /* ---- Hoefsmid / bekapper ---- */
      { id: 'sec-hoef',       label: 'Hoeven en beslag',                     type: 'sectionhead' },
      { id: 'hoefsmid-freq',  label: 'Hoe vaak komt de bekapper / hoefsmid?',   type: 'radio', required: true,
        options: ['elke 4 weken', 'elke 5 weken', 'elke 6 weken', 'elke 7–8 weken', 'op afroep'] },
      { id: 'hoeven-bijz',    label: 'Bijzonderheden mbt de voeten?',           type: 'multi', required: true,
        options: ['afwijkende hoefstand', 'white line disease', 'rotstraal', 'hoefkanker', 'regelmatig hoefzweren', 'geen', 'anders'],
        flagIf: ['afwijkende hoefstand', 'white line disease', 'rotstraal', 'hoefkanker', 'regelmatig hoefzweren'] },
      { id: 'hoeven-anders',  label: 'Anders, namelijk',                        type: 'text',
        showIf: { 'hoeven-bijz': 'anders' } },
      { id: 'ijzers',         label: 'Staat het paard op ijzers?',              type: 'radio', required: true,
        options: ['ja, rondom', 'ja, voor', 'ja, achter', 'nee', 'anders'] },

      /* ---- Zadel ---- */
      { id: 'sec-zadel',      label: 'Zadel',                                type: 'sectionhead' },
      { id: 'zadelmaker-freq',label: 'Hoe vaak komt de zadelmaker?',             type: 'text', required: true },
      { id: 'zadelmaker-laatst', label: 'Laatste bezoek zadelmaker',             type: 'date' },
      { id: 'zadel-bijz',     label: 'In het verleden bijzonderheden mbt het zadel?', type: 'textarea',
        flagIf: 'non-empty' },

      /* ---- Aangetoonde / vermoedelijke aandoeningen ---- */
      { id: 'sec-aandoening', label: 'Aangetoonde of vermoedelijke aandoeningen', type: 'sectionhead' },
      { id: 'ir-status',      label: 'Insulineresistentie (IR)',                 type: 'radio', required: true,
        options: ['aangetoond door dierenarts', 'vermoeden van mij', 'nee'],
        flagIf: ['aangetoond door dierenarts', 'vermoeden van mij'],
        protocolIf: {
          'aangetoond door dierenarts': 'Fase 1 wk 4: PankrEMS toevoegen 15 gr · 1× daags · min 3 mnd',
          'vermoeden van mij':          'Fase 1 wk 4: Schüsslerzouten nr. 10 + nr. 27 toevoegen' } },
      { id: 'ems-status',     label: 'EMS (Equine Metabolisch Syndroom)',        type: 'radio', required: true,
        options: ['aangetoond door dierenarts', 'nee', 'weet ik niet'],
        flagIf: ['aangetoond door dierenarts'],
        protocolIf: { 'aangetoond door dierenarts': 'Fase 1 wk 4: PankrEMS toevoegen 15 gr · 1× daags · min 3 mnd' } },
      { id: 'kpu-status',     label: 'KPU (Kryptopyrrolurie)',                   type: 'radio', required: true,
        options: ['aangetoond door dierenarts', 'vermoeden van mij', 'nee', 'weet ik niet'],
        flagIf: ['aangetoond door dierenarts', 'vermoeden van mij'],
        protocolIf: {
          'aangetoond door dierenarts': 'HeparKPU forte/EquiKPU toevoegen (12–18 mnd) · Vit B6+B12 en losse MSM weglaten in fase 2' } },
      { id: 'kpu-attest',     label: 'KPU-rapport beschikbaar?',                  type: 'file',
        showIf: { 'kpu-status': 'aangetoond door dierenarts' } },
      { id: 'hoefbevangenheid', label: 'Hoefbevangenheid (geweest of risico)',    type: 'radio', required: true,
        options: ['nu acuut', 'in verleden gehad', 'risico / vermoeden', 'nooit'],
        flagIf: ['nu acuut', 'in verleden gehad', 'risico / vermoeden'],
        criticalIf: ['nu acuut'],
        protocolIf: { 'nu acuut': 'Protocol blokkeren, los acute hoefbevangenheid eerst op',
                      'in verleden gehad': 'Zoethout extract weglaten uit fase 1',
                      'risico / vermoeden': 'Zoethout extract weglaten uit fase 1' } },

      /* ---- Maag ---- */
      { id: 'sec-maag',       label: 'Maag',                                 type: 'sectionhead' },
      { id: 'maag-ondersteuning', label: 'Heeft de maag ondersteuning nodig?',   type: 'radio', required: true,
        options: ['ja, ernstig / langdurig', 'ja, mild', 'nee'],
        flagIf: ['ja, ernstig / langdurig', 'ja, mild'],
        protocolIf: { 'ja, ernstig / langdurig': 'Fase 0 activeren · Gastercare 2× daags · 8 weken toevoegen',
                      'ja, mild': 'Fase 0 activeren, 6 weken' } },
      { id: 'maag-symptomen', label: 'Tekenen van maagproblemen',                type: 'multi',
        options: ['gevoelige buikriem', 'oren plat bij voeren', 'maagzweer aangetoond', 'soppen van hooi in water', 'gespannen bij eten', 'tandenknarsen', 'geen'],
        flagIf: 'any' },
      { id: 'maag-medicatie', label: 'Eerder gastro-medicatie gehad?',           type: 'radio',
        options: ['ja, herhaaldelijk', 'ja, één kuur', 'nee'],
        flagIf: ['ja, herhaaldelijk', 'ja, één kuur'] },

      /* ---- Darmen ---- */
      { id: 'sec-darm',       label: 'Darmen',                               type: 'sectionhead' },
      { id: 'gasserig',       label: 'Is je paard gasserig / opgeblazen?',       type: 'radio', required: true,
        options: ['ja, regelmatig', 'soms', 'nee'],
        flagIf: ['ja, regelmatig', 'soms'],
        protocolIf: { 'ja, regelmatig': 'Fase 1: Colobalance 50 gr · 1× daags · 14 dgn · onbeperkt ruwvoer',
                      'soms': 'Waarschuwing tonen: gasserigheid kan erger door gras, let op gaskoliek' } },
      { id: 'probiotica-geschiedenis', label: 'Jarenlang probiotica / gist / yeast gehad?', type: 'radio', required: true,
        options: ['ja, >2 jaar', 'ja, <2 jaar', 'nee', 'weet ik niet'],
        flagIf: ['ja, >2 jaar', 'ja, <2 jaar'],
        protocolIf: { 'ja, >2 jaar': 'Silsterk 7 dgn vóór fase 1 · dag 1+2: 2-3× 5 drpls · dan 2-3× 10 drpls',
                      'ja, <2 jaar': 'Silsterk 7 dgn vóór fase 1 · dag 1+2: 2-3× 5 drpls · dan 2-3× 10 drpls' } },
      { id: 'medicatie-nu',   label: 'Medicatie nu?',                            type: 'textarea',
        hint: 'Type, dosering, vanaf wanneer.',
        flagIf: 'non-empty',
        protocolIf: { 'non-empty': 'Waarschuwing tonen, protocol mag starten' } },
      { id: 'medicatie-recent',label: 'Medicatie laatste jaar?',                 type: 'textarea',
        flagIf: 'non-empty' },
    ],
  },

  /* ============= 05 · VOER & RUWVOER ============= */
  {
    id: 'voer', nr: 5,
    title: 'Voeding en ruwvoer',
    intro: 'Dit is vaak de kern. Wees specifiek, merknamen, hoeveelheden, exacte tijden.',
    minutes: 12,
    fields: [
      /* --- Ruwvoer basis --- */
      { id: 'sec-ruw',        label: 'Ruwvoer',                              type: 'sectionhead' },
      { id: 'hooi-verpakking', label: 'Eet je paard hooi uit plastic of uit touwtjes?', type: 'radio', required: true,
        options: ['onverpakt (touwtjes)', 'verpakt (plastic / baal)', 'mix'],
        flagIf: ['verpakt (plastic / baal)'] },
      { id: 'hooi-omschrijving', label: 'Hoe omschrijf je het ruwvoer?',         type: 'multi', required: true,
        options: ['zacht', 'grof', 'veel blad', 'veel stengels', 'groenig', 'gelig',
                  'veel verschillende planten', 'veel verschillende grassen', '1 of 2 soorten grassen',
                  'kruidig', 'eerste snede', 'tweede snede', 'rijk hooi', 'arm hooi'],
        flagIf: ['rijk hooi', 'gelig', '1 of 2 soorten grassen'] },
      { id: 'hooi-kwaliteit-geur', label: 'Kwaliteit & geur van het hooi',       type: 'multi', required: true,
        options: ['ruikt lekker', 'stoffig', 'neutraal', 'schimmelig', 'zuur'],
        flagIf: ['stoffig', 'schimmelig', 'zuur'] },
      { id: 'hooi-analyse',   label: 'Hooi-analyse aanwezig?',                   type: 'file',
        hint: 'Eén PDF, max 10 MB.' },
      { id: 'hooi-constant',  label: 'Is de kwaliteit constant of wisselend?',   type: 'radio', required: true,
        options: ['constant', 'wisselt per baal', 'wisselt per levering', 'wisselt per leverancier'],
        flagIf: ['wisselt per baal', 'wisselt per levering', 'wisselt per leverancier'] },
      { id: 'hooi-herkomst',  label: 'Eigen land of ingekocht?',                 type: 'radio', required: true,
        options: ['eigen land', 'ingekocht', 'mix', 'weet ik niet'] },
      { id: 'hooi-aanbod',    label: 'Hoe wordt het hooi gevoerd?',              type: 'textarea', required: true,
        hint: 'Los of in slowfeeders? Maasbreedte? Aantal voerplekken? Automatisch systeem? Continu of in porties?' },
      { id: 'hooi-pauze-incl-nacht', label: 'Bij porties: hoe lang zonder ruwvoer, INCL. nachten?', type: 'radio',
        options: ['<2 u', '2–4 u', '4–6 u', '6–8 u', '>8 u'],
        flagIf: ['4–6 u', '6–8 u', '>8 u'],
        protocolIf: { '4–6 u': 'Aanbeveling: slowfeeder ophangen',
                      '6–8 u': 'Verplicht: slowfeeder ophangen, geen pauze >2u',
                      '>8 u':  'Verplicht: slowfeeder ophangen, geen pauze >2u' } },
      { id: 'hooi-voerbeurten', label: 'In hoeveel voerbeurten?',                type: 'radio',
        options: ['1', '2', '3', '4', '>4', 'onbeperkt / continu'] },
      { id: 'hooi-eerst-ruwvoer', label: 'Krijgt het paard \'s ochtends eerst ruwvoer of krachtvoer?', type: 'radio',
        options: ['eerst ruwvoer', 'eerst krachtvoer', 'tegelijk'],
        flagIf: ['eerst krachtvoer'] },
      { id: 'hooi-kg-per-dag',label: 'Hoeveel kg ruwvoer krijgt je paard per dag?', type: 'number', unit: 'kg', required: true,
        hint: 'Heel veel mensen weten dit niet en gokken er flink naast. Weeg het een keer (bijv. met een weegschaal of bagageweger aan het hooinet) voordat je invult.' },
      { id: 'hooi-kg-methode',label: 'Is deze hoeveelheid geschat of gewogen?',    type: 'radio', required: true,
        options: ['gewogen', 'geschat'],
        hint: 'Eerlijk zijn helpt mij. Bij geschat reken ik met een ruimere marge.',
        flagIf: ['geschat'] },
      { id: 'foto-hooi',      label: 'Foto van het hooi',                        type: 'photo',
        hint: 'Eén hapje uit een baal, op een neutrale ondergrond.' },

      /* --- Voordroog / kuil --- */
      { id: 'sec-voordroog',  label: 'Voordroog en kuil',                     type: 'sectionhead' },
      { id: 'voordroog-verleden', label: 'Ooit voordroog of kuil gegeten?',      type: 'radio', required: true,
        options: ['ja, nu nog', 'ja, vroeger', 'nee'],
        flagIf: ['ja, nu nog'] },
      { id: 'voordroog-duur-vroeger', label: 'Hoe lang en hoeveel, in het verleden?', type: 'textarea',
        showIf: { 'voordroog-verleden': ['ja, vroeger'] } },
      { id: 'voordroog-nu-mix',  label: 'Volledig voordroog/kuil of gemixt met hooi?', type: 'radio',
        options: ['volledig', 'gemixt met onverpakt hooi'],
        showIf: { 'voordroog-verleden': 'ja, nu nog' } },
      { id: 'voordroog-kwaliteit', label: 'Kwaliteit voordroog/kuil',             type: 'multi',
        options: ['droog', 'nat', 'zuur', 'neutrale geur', 'anders'],
        showIf: { 'voordroog-verleden': 'ja, nu nog' },
        flagIf: ['nat', 'zuur'] },
      { id: 'voordroog-tempo',label: 'Hoe lang duurt 1 baal voordat een nieuwe wordt opengemaakt?', type: 'text',
        showIf: { 'voordroog-verleden': 'ja, nu nog' } },

      /* --- Stro --- */
      { id: 'sec-stro',       label: 'Stro',                                 type: 'sectionhead' },
      { id: 'stro-aanwezig',  label: 'Geef je wel eens stro?',                   type: 'radio', required: true,
        options: ['ja', 'nee'] },
      { id: 'stro-manier',    label: 'Op wat voor manier?',                      type: 'multi',
        options: ['als bodembedekking', 'in slowfeeders', 'gemixt met hooi', 'anders'],
        showIf: { 'stro-aanwezig': 'ja' } },
      { id: 'stro-type',      label: 'Wat voor stro?',                           type: 'text',
        showIf: { 'stro-aanwezig': 'ja' } },
      { id: 'stro-hoeveelheid', label: 'Hoeveel stro eet je paard per dag?',     type: 'text',
        hint: 'Zou je hem/haar een "stro-eter" noemen?',
        showIf: { 'stro-aanwezig': 'ja' } },
      { id: 'stro-kwaliteit', label: 'Kwaliteit stro',                           type: 'multi',
        options: ['gelig', 'grijzig', 'ruikt fris', 'ruikt schimmelig', 'ruikt chemisch', 'anders'],
        showIf: { 'stro-aanwezig': 'ja' },
        flagIf: ['grijzig', 'ruikt schimmelig', 'ruikt chemisch'] },

      /* --- Voerwissel & krachtvoer --- */
      { id: 'sec-kracht',     label: 'Krachtvoer en bijvoer',                 type: 'sectionhead' },
      { id: 'voer-gewisseld', label: 'Recent gewisseld van (ruw)voer?',          type: 'radio', required: true,
        options: ['ja, <3 maanden', 'ja, <6 maanden', 'nee'],
        flagIf: ['ja, <3 maanden'] },
      { id: 'voer-gewisseld-toelichting', label: 'Toelichting voerwissel',       type: 'textarea',
        hint: 'Wat kreeg ze hiervoor en wanneer overgegaan?',
        showIf: { 'voer-gewisseld': ['ja, <3 maanden', 'ja, <6 maanden'] } },
      { id: 'krachtvoer-detail', label: 'Welk krachtvoer en in welke hoeveelheid?', type: 'textarea', required: true,
        hint: 'Merknamen + type EXACT benoemen. Bv. "Pavo Slobber, 1 schep per ochtend".' },
      { id: 'bijvoer-5jaar',  label: 'Bijvoer afgelopen 5 jaar',                  type: 'textarea', required: true,
        hint: 'Muesli\'s, granen, bietenpulp, gehakseld ruwvoer, enz.' },
      { id: 'balancer',       label: 'Balancer / mineralenvoeding?',              type: 'textarea', required: true,
        hint: 'Merk, type, hoeveelheid.' },
      { id: 'mineralen-toegang', label: 'Toegang tot welke mineralen?',           type: 'multi', required: true,
        options: ['gewone liksteen', 'Himalaya zoutsteen', 'Veendrenkstof', 'IJslands zeewier',
                  'Keltisch zeezout', 'PN natuursteen', 'MSM (zwavel)', 'geen', 'anders'] },

      /* --- Snacks & supplementen --- */
      { id: 'sec-supp',       label: 'Snacks en supplementen',                type: 'sectionhead' },
      { id: 'snacks-aanwezig',label: 'Krijgt je paard snacks?',                  type: 'radio', required: true,
        options: ['ja', 'nee'] },
      { id: 'snacks-detail',  label: 'Wat geef je precies en hoeveel?',           type: 'textarea',
        hint: 'Wortel, appel, fruit, brood, paardensnoepjes (met merk/type), …',
        showIf: { 'snacks-aanwezig': 'ja' } },
      { id: 'huidig-extra',   label: 'Alle huidige medicijnen, supplementen, bijvoeding', type: 'repeater', required: true,
        hint: 'Hoeveelheid, merk, sinds wanneer.',
        sub: [
          { id: 'naam',       label: 'Naam',          type: 'text' },
          { id: 'merk',       label: 'Merk / type',   type: 'text' },
          { id: 'dosering',   label: 'Dosering',      type: 'text' },
          { id: 'sinds',      label: 'Sinds wanneer', type: 'text' },
          { id: 'reden',      label: 'Waarom',        type: 'text' },
        ],
        flagIf: 'any' },
      { id: 'historie-extra', label: 'Alle medicijnen / supplementen / bijvoeding LAATSTE 5 JAAR', type: 'repeater', required: true,
        hint: 'Hoeveelheid, merk, periode (van–tot).',
        sub: [
          { id: 'naam',       label: 'Naam',          type: 'text' },
          { id: 'merk',       label: 'Merk / type',   type: 'text' },
          { id: 'dosering',   label: 'Dosering',      type: 'text' },
          { id: 'periode',    label: 'Periode (van – tot)', type: 'text' },
        ] },
    ],
  },

  /* ============= 06 · WATER & UITSCHEIDING ============= */
  {
    id: 'water', nr: 6,
    title: 'Drinken, plassen en mesten',
    intro: 'Hoe drinkt ze, en wat komt eruit.',
    minutes: 5,
    fields: [
      { id: 'water-type',     label: 'Wat voor water drinkt het paard?',         type: 'radio', required: true,
        options: ['leidingwater', 'regenwater', 'grondwater', 'slootwater / oppervlaktewater', 'weet ik niet', 'anders'],
        flagIf: ['slootwater / oppervlaktewater', 'weet ik niet'] },
      { id: 'water-analyse',  label: 'Water-analyse aanwezig?',                  type: 'file',
        hint: 'Vooral relevant als geen leidingwater, upload PDF.',
        showIf: { 'water-type': ['regenwater', 'grondwater', 'slootwater / oppervlaktewater', 'anders'] } },
      { id: 'water-aanbod',   label: 'Hoe wordt het water aangeboden?',          type: 'radio', required: true,
        options: ['speciekuipen / plastic emmers / troggen', 'automatische drinkbakken', 'anders'] },
      { id: 'water-toegang',  label: 'Hele dag toegang tot drinkwater?',         type: 'radio', required: true,
        options: ['ja', 'nee'],
        flagIf: ['nee'] },
      { id: 'water-kwaliteit',label: 'Hoe omschrijf je de kwaliteit van het water?', type: 'multi', required: true,
        options: ['neutraal / schoon', 'zwavelachtig', 'algen aanwezig', 'aanslag', 'modderig',
                  'ijzer / zware metalen', 'gelig', 'bruinig', 'groenig', 'koud', 'lauw'],
        flagIf: ['zwavelachtig', 'algen aanwezig', 'aanslag', 'modderig', 'ijzer / zware metalen', 'gelig', 'bruinig', 'groenig'] },
      { id: 'vochtinname',    label: 'Hoe is de vochtinname?',                   type: 'radio', required: true,
        options: ['lijkt normaal', 'lijkt minder', 'lijkt meer', 'weet ik niet'],
        flagIf: ['lijkt minder', 'lijkt meer'] },

      /* --- Urine & mest --- */
      { id: 'sec-uit',        label: 'Urine en mest',                         type: 'sectionhead' },
      { id: 'urine',          label: 'Urine',                                    type: 'multi',
        options: ['helder', 'gelig', 'oranje', 'troebel', 'normaal volume', 'minder', 'meer'],
        flagIf: ['oranje', 'troebel', 'minder', 'meer'] },
      { id: 'mest-freq',      label: 'Mest-frequentie per dag',                  type: 'radio',
        options: ['<6×', '6–10×', '10–14×', '>14×'] },
      { id: 'mest-geur',      label: 'Geur van mest',                            type: 'radio',
        options: ['normaal', 'iets penetrant', 'sterk / rottend'],
        flagIf: ['iets penetrant', 'sterk / rottend'] },
      { id: 'mest-kleur',     label: 'Kleur van mest',                           type: 'radio',
        options: ['groen', 'bruingroen', 'bruin', 'donkerbruin', 'zwart'],
        flagIf: ['zwart'] },
      { id: 'mest-vorm',      label: 'Hoe ziet de vorm en vastheid van de mest eruit?', type: 'radio', required: true,
        options: ['waterig / diarree', 'zacht of smeuïg (geen vaste appels)', 'normale, vaste appels', 'droog en hard', 'wisselt per dag'],
        flagIf: ['waterig / diarree', 'zacht of smeuïg (geen vaste appels)', 'droog en hard', 'wisselt per dag'],
        protocolIf: {
          'waterig / diarree': 'Fase 1b kandidaat, overweeg Aardpeerpellets 50 tot 100 gr gedurende 2 weken',
          'zacht of smeuïg (geen vaste appels)': 'Fase 1b kandidaat bij aanhouden na evaluatie week 6' } },
      { id: 'foto-mest',      label: 'Foto van mest (laatste 24u)',              type: 'photo', required: true,
        hint: 'Eén hoopje, op een schone ondergrond. Cruciale info voor mij.' },
    ],
  },

  /* ============= 07 · HUISVESTING & WEIDEGANG ============= */
  {
    id: 'huisvesting', nr: 7,
    title: 'Huisvesting en weidegang',
    intro: 'Waar woont ze, zomer én winter, en met wie.',
    minutes: 8,
    fields: [

      /* --- Huisvesting algemeen --- */
      { id: 'sec-huis',       label: 'Huisvesting (zomer en winter)',         type: 'sectionhead' },
      { id: 'huisvesting-zomer', label: 'Hoe wordt je paard in de zomer gehuisvest?', type: 'textarea', required: true,
        hint: 'Individuele box, box met uitloop, paddock, 24/7 weidegang, …' },
      { id: 'huisvesting-winter', label: 'Hoe in de winter?',                    type: 'textarea', required: true },
      { id: 'stal-uren',      label: 'Hoeveel uur staat hij/zij per dag op stal?', type: 'radio', required: true,
        options: ['0 u', '1–4 u', '4–8 u', '8–12 u', '12–18 u', '>18 u', '24 u'],
        flagIf: ['12–18 u', '>18 u', '24 u'] },
      { id: 'buiten-niet-stal', label: 'Als niet op stal, hoe wordt het paard dan gehuisvest?', type: 'multi', required: true,
        options: ['zandpaddock', 'weiland', 'verharding', 'kunststofvloer', 'anders'] },
      { id: 'bodembedekking', label: 'Bodembedekking in de stal',                type: 'multi', required: true,
        options: ['stro', 'houtkrullen', 'vlas', 'zaagsel', 'rubberen matten', 'zand', 'geen', 'anders'],
        flagIf: ['zaagsel'] },
      { id: 'foto-stal',      label: 'Foto van haar stal of paddock',            type: 'photo' },

      /* --- Weidegang --- */
      { id: 'sec-weide',      label: 'Weidegang',                             type: 'sectionhead' },
      { id: 'gras-maanden',   label: 'Hoeveel maanden per jaar op gras?',         type: 'radio', required: true,
        options: ['0', '1–2', '3–4', '5–6', '7–8', '>8', 'jaarrond'] },
      { id: 'gras-uren',      label: 'In die maanden, hoeveel uur per dag op gras?', type: 'radio', required: true,
        options: ['0', '1–2 u', '3–4 u', '5–8 u', '9–12 u', '>12 u', '24 u'] },
      { id: 'nooit-gras',     label: 'Komt ze ÓÓIT op echt gras (ook niet 2u/dag in zomer)?', type: 'radio', required: true,
        options: ['ja, regelmatig', 'ja, sporadisch', 'nee, nooit'],
        hint: 'Cruciaal voor de vitamine E vraag, alleen "nee" = nooit een seconde.',
        flagIf: ['nee, nooit'],
        protocolIf: { 'nee, nooit': 'Natuurlijke vit. E (RRR-complex, zonder selenium) 6-8 wk · LOSSE MSM weglaten' } },
      { id: 'mestwater-overgang', label: 'Mestwater bij transitie hooi ↔ gras?', type: 'multi', required: true,
        options: ['ja, begin', 'ja, einde', 'nee'],
        flagIf: ['ja, begin', 'ja, einde'] },
      { id: 'gras-type',      label: 'Type grasland',                            type: 'multi', required: true,
        options: ['paardengras', 'koeiengras', 'kruidenrijk', 'weet ik niet', 'anders'],
        flagIf: ['koeiengras'] },
      { id: 'gras-bemest',    label: 'Bemest of niet bemest?',                   type: 'radio',
        options: ['niet bemest', 'wel bemest', 'weet ik niet'],
        flagIf: ['wel bemest'] },
      { id: 'bomen-struiken', label: 'Bomen of struiken aan / in de weide',      type: 'textarea',
        hint: 'Welke soorten? Bereikbaar?',
        flagIf: 'non-empty' },

      /* --- Sociale huisvesting --- */
      { id: 'sec-sociaal',    label: 'Sociale huisvesting',                   type: 'sectionhead' },
      { id: 'groep-grootte',  label: 'Hoeveel paarden in de groep?',              type: 'number', unit: 'paarden' },
      { id: 'groep-mix',      label: 'Ruinen en merries apart of samen?',         type: 'radio',
        options: ['samen', 'apart', 'n.v.t.'] },
      { id: 'groep-leeftijden', label: 'Min – max leeftijd in de groep',          type: 'text',
        hint: 'Bv. "4 t/m 18 jaar".' },
      { id: 'groep-interactie', label: 'Hoe is de sociale interactie?',           type: 'textarea',
        flagIf: 'non-empty' },
      { id: 'groep-maatjes',  label: 'Heeft jouw paard specifieke maatjes?',      type: 'textarea',
        hint: 'Wat voor paard(en)?' },
      { id: 'groep-hierarchie', label: 'Waar staat jouw paard in de hiërarchie?', type: 'radio',
        options: ['bovenin', 'midden', 'onderaan', 'wisselend', 'weet ik niet'] },
      { id: 'groep-ruimte',   label: 'Genoeg ruimte om zich af te zonderen?',     type: 'radio',
        options: ['ja', 'matig', 'nee'],
        flagIf: ['matig', 'nee'] },
      { id: 'groep-pesten',   label: 'Pestgedrag, stress of depressie zichtbaar?', type: 'textarea',
        flagIf: 'non-empty' },
      { id: 'andere-dieren',  label: 'Andere diersoorten in de buurt',            type: 'multi',
        options: ['schapen', 'koeien', 'geiten', 'kippen', 'honden', 'katten', 'wild'] },
    ],
  },

  /* ============= 08 · GEDRAG, TRAINING & KARAKTER ============= */
  {
    id: 'gedrag', nr: 8,
    title: 'Gedrag, training en karakter',
    intro: 'Wie is ze? Hoe gaat ze met de wereld om, en hoe wordt ze ingezet?',
    minutes: 6,
    fields: [
      /* --- Beweging / training --- */
      { id: 'sec-bew',        label: 'Beweging en training',                  type: 'sectionhead' },
      { id: 'beweging-arbeid', label: 'Hoeveel dagelijkse beweging mbt arbeid?', type: 'text', required: true,
        hint: 'Aantal uren per dag, en wat voor soort werk.' },
      { id: 'discipline',     label: 'Welke discipline?',                        type: 'text', required: true },
      { id: 'training-freq',  label: 'Hoe vaak per week getraind?',              type: 'radio', required: true,
        options: ['0×', '1–2×', '3–4×', '5–7×'] },
      { id: 'training-intensiteit', label: 'Gemiddelde intensiteit',             type: 'radio', required: true,
        options: ['licht', 'matig', 'zwaar', 'wisselend'] },
      { id: 'training-knelpunten', label: 'Specifieke dingen tijdens training waar je tegenaan loopt?', type: 'textarea', required: true,
        flagIf: 'non-empty' },
      { id: 'conditie-eigen', label: 'Wat vind je van haar conditie?',           type: 'textarea', required: true },

      /* --- Stalondeugden & opvallend gedrag --- */
      { id: 'sec-stal',       label: 'Stalondeugden en opvallend gedrag',     type: 'sectionhead' },
      { id: 'stress-symptomen', label: 'Stress-symptomen?',                      type: 'multi', required: true,
        options: ['weven', 'boxlopen', 'kribbenbijten', 'luchtzuigen', 'flemen', 'geen'],
        flagIf: ['weven', 'boxlopen', 'kribbenbijten', 'luchtzuigen', 'flemen'] },
      { id: 'headshaking',    label: 'Headshaking?',                             type: 'radio', required: true,
        options: ['ja, regelmatig', 'soms', 'nee'],
        flagIf: ['ja, regelmatig', 'soms'] },
      { id: 'agressie',       label: 'Overmatig agressief of beschermend?',      type: 'radio', required: true,
        options: ['ja', 'nee'],
        flagIf: ['ja'] },
      { id: 'agressie-detail', label: 'Beschrijving',                             type: 'textarea',
        showIf: { agressie: 'ja' } },
      { id: 'typisch-gedrag', label: 'Typisch / apart / onverklaarbaar gedrag?', type: 'multi', required: true,
        options: ['soppen van hooi', 'gretigheid naar specifiek voer', 'kieskeurig met voer',
                  'gevoelige buik', 'boos bij aansingelen', 'oren plat bij verzorging',
                  'gevoelig op rug', 'kan niet stilstaan', 'geen'],
        flagIf: 'any' },
      { id: 'typisch-gedrag-detail', label: 'Beschrijving',                       type: 'textarea',
        showIf: { 'typisch-gedrag': 'any-checked' } },
      { id: 'soortgenoten',   label: 'Omgang met soortgenoten',                  type: 'multi',
        options: ['vriendelijk', 'dominant', 'onderworpen', 'gespannen', 'speels', 'afstandelijk'] },
      { id: 'stress-triggers',label: 'Waar krijgt ze stress van?',               type: 'textarea' },
      { id: 'karakter',       label: 'Karakter, in eigen woorden, beknopt',     type: 'textarea', required: true,
        hint: 'Wat maakt haar uniek.' },
    ],
  },

  /* ============= 09 · FYSIEK & FOTO'S ============= */
  {
    id: 'fysiek', nr: 9,
    title: 'Lichamelijke check en foto\'s',
    intro: 'Een korte check + foto\'s. Geen perfecte fotograaf nodig, daglicht is genoeg.',
    minutes: 10,
    fields: [
      { id: 'huid',           label: 'Hoe ziet de huid eruit?',                  type: 'multi', required: true,
        options: ['droog', 'vet', 'rood', 'schilfers', 'kale plekken', 'wratten', 'mok / krukken', 'normaal'],
        flagIf: ['rood', 'schilfers', 'kale plekken', 'mok / krukken'] },
      { id: 'haar',           label: 'Vacht / haar',                             type: 'multi', required: true,
        options: ['glanzend', 'dof', 'kruinen', 'verkleurd', 'wisselt sterk met seizoen', 'kaal in plekken'],
        flagIf: ['dof', 'kruinen', 'verkleurd', 'kaal in plekken'] },
      { id: 'hoeven-kwaliteit',label: 'Hoeven, visueel',                         type: 'multi',
        options: ['sterk', 'brokkelig', 'gelijke afslijting', 'ongelijke afslijting', 'kloven', 'ringen', 'lange teen'],
        flagIf: ['brokkelig', 'ongelijke afslijting', 'kloven', 'ringen', 'lange teen'] },
      { id: 'slijmvliezen',   label: 'Slijmvliezen (tandvlees, oogwit)',         type: 'multi',
        options: ['roze', 'bleek', 'donkerrood', 'gelig', 'plakkerig', 'normaal vochtig'],
        flagIf: ['bleek', 'donkerrood', 'gelig', 'plakkerig'] },
      { id: 'ademhaling',     label: 'Ademhaling in rust',                       type: 'radio', required: true,
        options: ['rustig', 'iets snel', 'hijgend', 'piepend', 'hoest'],
        flagIf: ['iets snel', 'hijgend', 'piepend', 'hoest'] },
      { id: 'bouw',           label: 'Bouw',                                     type: 'multi',
        options: ['rank', 'zwaar', 'kort', 'lang', 'gespierd', 'slank'] },
      { id: 'bespiering',     label: 'Bespiering links–rechts gelijk?',          type: 'radio',
        options: ['ja, gelijk', 'links meer', 'rechts meer', 'voor meer dan achter', 'achter meer dan voor'],
        flagIf: ['links meer', 'rechts meer', 'voor meer dan achter', 'achter meer dan voor'] },
      { id: 'fysiek-vrij',    label: 'Iets fysieks dat je opvalt en niet hierboven past?', type: 'textarea' },

      { id: 'foto-zijaanzicht', label: 'Foto · hele paard van opzij',            type: 'photo', required: true,
        hint: 'Op 3 m afstand, recht van opzij. Stilstaand op 4 benen.' },
      { id: 'foto-vooraanzicht',label: 'Foto · vooraanzicht',                    type: 'photo', required: true },
      { id: 'foto-achteraanzicht', label: 'Foto · achteraanzicht',               type: 'photo', required: true },
      { id: 'foto-huid',      label: 'Foto · plek waar de klacht zit',           type: 'photo', required: true,
        hint: 'Close-up. Zoom in, niet ver weg.' },
      { id: 'foto-hoeven',    label: 'Foto · alle 4 hoeven',                     type: 'photo', required: true,
        hint: 'Per hoef vanaf voor + zijaanzicht.' },
      { id: 'foto-slijmvlies',label: 'Foto · slijmvlies (tandvlees of oogwit)',  type: 'photo',
        hint: 'Optioneel maar enorm waardevol.' },
    ],
  },

  /* ============= 10 · CONTROLEREN & VERSTUREN ============= */
  {
    id: 'samenvatting', nr: 10,
    title: 'Controleren en versturen',
    intro: 'Loop nog een keer door je antwoorden. Aanpassen kan, ook later.',
    minutes: 2,
    fields: [
      { id: 'opmerking-vrij', label: 'Iets dat ik moet weten en niet in de vragen paste?', type: 'textarea' },
      { id: 'akkoord-foto',   label: 'Mijn foto\'s mogen anoniem gebruikt worden in lesmateriaal', type: 'radio', required: true,
        options: ['ja', 'nee'] },
      { id: 'akkoord-data',   label: 'Ik ga akkoord met de privacyverklaring',   type: 'radio', required: true,
        options: ['ja'] },
    ],
  },
];

window.INTAKE_FORM_SCHEMA = INTAKE_FORM_SCHEMA;

/* ============================================================
   AUTO-PROTOCOL DECISION TREE, welk antwoord triggert wat?
   Dit is wat het systeem afdraait bij verzenden van de intake.
   ============================================================ */
const PROTOCOL_DECISION_TREE = [
  /* --- Veiligheidschecks (blokkeren) --- */
  { kind: 'block',  als: 'paard.drachtig ∈ {drachtig, lacterend}',
    dan: 'Protocol blokkeren · "Wacht tot dracht- of lactatieperiode voorbij is."' },
  { kind: 'block',  als: 'klacht.acuut bevat koorts | ernstige kreupelheid | koliek',
    dan: 'Protocol blokkeren · "Los acute klacht eerst op. Herstart daarna."' },
  { kind: 'block',  als: 'medisch.hoefbevangenheid = nu acuut',
    dan: 'Protocol blokkeren · "Los acute hoefbevangenheid eerst op."' },

  /* --- Waarschuwingen (mag starten) --- */
  { kind: 'warn',   als: 'medisch.medicatie-nu = non-empty',
    dan: 'Waarschuwing tonen · protocol mag starten' },
  { kind: 'warn',   als: 'medisch.gasserig ∈ {ja, soms}',
    dan: 'Waarschuwing: "Gasserigheid kan erger worden door gras. Let op gaskoliek."' },

  /* --- Maag-ondersteuning (fase 0) --- */
  { kind: 'phase',  als: 'medisch.maag-ondersteuning ∈ {mild, ernstig}',
    dan: 'Fase 0 activeren · 6 wk · fase 1 pas starten na 2 wk fase 0' },
  { kind: 'addon',  als: 'medisch.maag-ondersteuning = ernstig / langdurig',
    dan: 'Gastercare toevoegen · 2× daags · 8 weken' },

  /* --- IR / EMS modificaties --- */
  { kind: 'modify', als: 'medisch.ir-status = aangetoond OR medisch.ems-status = aangetoond',
    dan: 'Fase 1 wk 4: PankrEMS toevoegen · 15 gr · 1× daags · minimaal 3 mnd' },
  { kind: 'modify', als: 'medisch.ir-status = vermoeden',
    dan: 'Fase 1 wk 4: Schüsslerzouten nr. 10 + nr. 27 · 3 tabl/celzout · 2× daags · 8 wk' },
  { kind: 'modify', als: 'medisch.ir-status ∈ {aangetoond, vermoeden}',
    dan: 'Fase 0: psylliumzaad ipv lijnzaad · 175–200 gr · 8 weken' },

  /* --- KPU modificaties --- */
  { kind: 'modify', als: 'medisch.kpu-status = aangetoond',
    dan: 'HeparKPU forte of EquiKPU toevoegen · 12–18 mnd · Vit B6+B12 + losse MSM weglaten in fase 2' },

  /* --- Hoefbevangenheid modificaties --- */
  { kind: 'modify', als: 'medisch.hoefbevangenheid ∈ {verleden, risico}',
    dan: 'Zoethout extract weglaten uit fase 1' },

  /* --- Probiotica voorgeschiedenis --- */
  { kind: 'modify', als: 'medisch.probiotica-geschiedenis ∈ {ja >2j, ja <2j}',
    dan: 'Silsterk · 7 dgn vóór fase 1 · dag 1+2: 2-3× 5 drpls · dan 2-3× 10 drpls' },

  /* --- Gasserigheid --- */
  { kind: 'addon',  als: 'medisch.gasserig = ja, regelmatig',
    dan: 'Fase 1: Colobalance · 50 gr · 1× daags · 14 dgn · onbeperkt ruwvoer' },

  /* --- Nooit op gras --- */
  { kind: 'modify', als: 'huisvesting.nooit-gras = nee, nooit',
    dan: 'Natuurlijke vit. E (RRR-complex Pharmahorse, zónder selenium) · 6–8 wk · Losse MSM weglaten' },

  /* --- Mestwater / waterige mest --- */
  { kind: 'addon',  als: 'water.mest-vorm = waterig',
    dan: 'Fase 1b kandidaat · Aardpeerpellets 50–100 gr · 2 wk' },

  /* --- Ruwvoer-pauze --- */
  { kind: 'addon',  als: 'voer.hooi-pauze-incl-nacht > 4u',
    dan: 'Aanbeveling: slowfeeder ophangen, geen pauze >2u' },

  /* --- Doseergewicht --- */
  { kind: 'compute', als: 'altijd',
    dan: 'Alle doseringen herberekenen op basis van paard.gewicht (template baseline = 600 kg)' },
];

window.PROTOCOL_DECISION_TREE = PROTOCOL_DECISION_TREE;

/* ============================================================
   FLAG-REGELS, wat surfacet als aandachtspunt op Shelley's review
   ============================================================ */
const THERAPIST_FLAG_RULES = [
  { name: 'Eigenaar twijfelt over aanpassen',  source: 'contact.aanpassingen-openheid' },
  { name: 'Conditie te zwaar of te mager',     source: 'paard.conditie' },
  { name: 'Acute klacht aanwezig',             source: 'klacht.acuut' },
  { name: 'Stress / verhuizing recent',        source: 'klacht.stressfactoren', when: 'non-empty' },
  { name: 'Diagnose dierenarts aanwezig',      source: 'klacht.da-diagnose', when: 'non-empty' },
  { name: 'Eerdere ziektegeschiedenis',        source: 'geschiedenis.medische-geschiedenis-volledig' },
  { name: 'Moeder had metabolische klachten',  source: 'geschiedenis.moeder-metabolisch' },
  { name: 'Maagsymptomen aanwezig',            source: 'medisch.maag-symptomen' },
  { name: 'Hoefbijzonderheden',                source: 'medisch.hoeven-bijz' },
  { name: 'IR / EMS / KPU aangetoond/vermoed', source: 'medisch.ir-status / ems-status / kpu-status' },
  { name: 'Hoefbevangenheid in geschiedenis',  source: 'medisch.hoefbevangenheid' },
  { name: 'Medicatie nu of recent',            source: 'medisch.medicatie-*' },
  { name: 'Probiotica-voorgeschiedenis',       source: 'medisch.probiotica-geschiedenis' },
  { name: 'Wisselende ruwvoerkwaliteit',       source: 'voer.hooi-constant' },
  { name: 'Rijk / kort grasland',              source: 'voer.hooi-omschrijving' },
  { name: 'Voordroog of kuil aanwezig',        source: 'voer.voordroog-verleden', when: 'ja, nu nog' },
  { name: 'Recente voerwisseling',             source: 'voer.voer-gewisseld' },
  { name: 'Supplementen lopen',                source: 'voer.huidig-extra', when: 'any' },
  { name: 'Bemest gras of koeiengras',         source: 'huisvesting.gras-type / gras-bemest' },
  { name: 'Stal >18u per dag',                 source: 'huisvesting.stal-uren' },
  { name: 'Bomen / struiken op weiland',       source: 'huisvesting.bomen-struiken', when: 'non-empty' },
  { name: 'Stalondeugd aanwezig',              source: 'gedrag.stress-symptomen' },
  { name: 'Headshaking',                       source: 'gedrag.headshaking' },
  { name: 'Boos bij singelen / opvallend',     source: 'gedrag.typisch-gedrag' },
  { name: 'Bespiering ongelijk',               source: 'fysiek.bespiering' },
  { name: 'Huid- of vachtafwijking',           source: 'fysiek.huid / fysiek.haar' },
  { name: 'Slijmvlies afwijking',              source: 'fysiek.slijmvliezen' },
  { name: 'Ademhaling afwijkend',              source: 'fysiek.ademhaling' },
  { name: 'Mestkleur / vorm afwijkend',        source: 'water.mest-*' },
  { name: 'Vochtinname afwijkend',             source: 'water.vochtinname' },
  { name: 'Niet-leidingwater zonder analyse',  source: 'water.water-type / water-analyse' },
];

window.THERAPIST_FLAG_RULES = THERAPIST_FLAG_RULES;
