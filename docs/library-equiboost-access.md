# Bibliotheektoegang en EquiBoost

De admin kiest per item één toegangsvorm:

- **Gratis voor alle abonnees**: geen credits nodig.
- **Met credits**: de ingestelde creditprijs geldt voor Basic en EquiBoost.
- **Plus only — alleen tijdens EquiBoost**: alleen tijdelijke EquiBoost-toegang; geen nieuwe creditverkoop aan Basic.
- **Credits voor Basic — gratis tijdens EquiBoost**: de beheerder stelt een positieve gehele creditprijs in. Deze is niet vastgezet op één credit.

Eerder met credits ontgrendelde items blijven toegankelijk, ook na een beleidswijziging naar Plus only. Tijdelijke gratis toegang maakt geen permanente aankoop aan. Een actief regulier abonnement blijft vereist.

## EquiBoost-periode

Toegang geldt voor de eigenaar van een actief paard met een gepubliceerd, actief persoonlijk protocol. De eerste activatie/publicatie legt `protocols.equiboost_started_at` vast. De periode eindigt drie kalendermaanden later. Pauzeren of opnieuw publiceren reset deze datum niet. Bij toekomstige publicatie begint de periode op die publicatiedatum. Bestaande gepubliceerde protocollen worden bij migratie vanaf hun bestaande publicatiedatum gerekend.

`library_items.equiboost_access` bevat `only`, `included` of NULL. De bestaande `credit_cost` blijft de prijs; er is geen tweede prijssysteem. Het oude `is_plus` blijft ondersteund voor bestaande data en oudere clients, en betekent regulier inbegrepen/gratis, niet EquiBoost.

## Uitrollen

1. Rol backendcode uit en voer de Laravel-migraties uit. De migratie breidt de bestaande toegangsprojectie en brontriggers uit.
2. Publiceer de bijgewerkte PowerSync-regels (`backend/powersync/sync_rules.yaml`) met het nieuwe catalogusveld. De bestaande private contentstream gebruikt de toegangsprojectie en vervaldatum.
3. Bouw en publiceer de admin en app met het bijgewerkte lokale PowerSync-schema. De app controleert vervaldatums ook offline; bestaande aankopen blijven apart bewaard.

De API, credittransacties, ondertekende PDF-links en PowerSync-projectie controleren dezelfde toegang. Exportbundels gebruiken versie 4; import van oude bundels zonder toegangsvorm behoudt een bestaande EquiBoost-instelling.

Bij terugrollen maakt de migratie items met EquiBoost-beperkingen ongepubliceerd, omdat de oude backend deze beperkingen niet kan afdwingen.
