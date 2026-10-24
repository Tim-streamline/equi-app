# Voerscanner: versiegebonden beoordelingen

De admin kan bij **Beoordelingsregels & kennis** een concept met scoreplafonds aanpassen en activeren. Alleen de actieve, gepubliceerde regelversie wordt gebruikt bij een nieuwe berekening. Een nieuw concept verandert bestaande berekeningen niet. Standaardvoer en balancers hebben ieder een eigen profiel.

De initiële afspraken zijn: suiker + zetmeel vanaf 20% geeft 0/10; boven 15% maximaal 4; boven 12% maximaal 5. Bevestigd organisch selenium begrenst op 3; bevestigde toevoeging met onbekende vorm op 5,5. Bevestigd anorganisch selenium heeft geen seleniumplafond. Een analytische seleniumtotaalwaarde bewijst geen toevoeging. De strengste toepasselijke grens geldt ook bij een handmatige beoordeling.

Een plafond is geen eindcijfer. Alleen een bewezen plafond van nul levert zonder verdere kalibratie automatisch een eindscore op. Voor andere producten blijft het automatische cijfer leeg. Er zijn geen fictieve strafpunten of uitgangscijfers toegevoegd.

## Productgegevens en ontbrekende informatie

Vergelijking gebeurt op productbasis. Droge-stofwaarden worden alleen omgerekend met een betrouwbare droge-stof- of vochtwaarde uit dezelfde productversie. Onbekende analysebasis wordt niet ingevuld. Exacte decimale berekening voorkomt afrondingsfouten bij grenswaarden. Minimum-, maximum- en strikte grenswaarden worden als intervallen verwerkt: alleen aantoonbaar toepasselijke plafonds worden geactiveerd. Tegenstrijdige of onvolledige gegevens blijven zichtbaar als ontbrekende informatie.

## Beoordelingen en historie

Open **Merken & producten → Beoordelingen & historie**. Een berekening bewaart productversie, regelversie, motorversie, volledige invoer, gebruikte regels, bevindingen, ontbrekende gegevens en verschillen met de vorige berekening. Berekeningen van conceptproducten zijn herkenbaar als voorbeeldberekening en publiceren het product niet.

Een beheerder kan het nieuwste resultaat onder de actuele regels handmatig beoordelen, met verplichte motivatie. Het oorspronkelijke automatische cijfer en alle eerdere correcties blijven bewaard. Herberekenen neemt een eerdere handmatige score niet over. Bij ontbrekende essentiële gegevens kan geen handmatig eindcijfer worden toegekend, behalve wanneer een bewezen nulplafond het resultaat al bepaalt.

De migratie `2026_10_23_100000_create_feed_assessments.php` voegt historie toe en maakt nieuwe regelconcepten; zij activeert geen beleid en overschrijft geen oude versies. Maak vooraf een databaseback-up en voer daarna migratie en adminbuild uit. Destructieve rollback wordt geweigerd.

De drie aangeleverde productvoorbeelden staan in `tests/Fixtures/feed-calibration-2026-10-23.json`. De cijfers 8, 6 en 0 zijn menselijke kalibratiereferenties, geen automatische uitgangscijfers. Lokale conceptproducten en private etiketbestanden worden niet door deze migratie in andere omgevingen aangemaakt.

AI-etiketherkenning, gekalibreerde puntenweging, paardenprofielen en aansluiting op de publieke scanner zijn vervolgfases. Bestaande legacy-scans worden niet achteraf als gevalideerde motorresultaten aangemerkt.
