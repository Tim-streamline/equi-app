import { formatIntakeDate } from '@/lib/intake/submission';
import { useQuery } from '@powersync/react';
import { Pressable, Text, View } from 'react-native';
import { router } from 'expo-router';
import { useCurrentHorseId, useCurrentUserId } from '@/db/hooks';
import { useDb } from '@/db/provider';
import { PLAIN_CARD, HOME_NOTICE_LABEL, HOME_NOTICE_BODY, HOME_NOTICE_LINK } from '@/components/ui/styles';
import { horsePossessive } from '@/lib/protocol-evaluation';
import { PROTOCOLS_FOR_ALL_HORSES, PROTOCOLS_FOR_HORSE, type PurchasedProtocolRow } from '@/lib/intake/protocol-queries';

/** What a purchased protocol's card says and whether it opens anything. */
function cardState(protocol: PurchasedProtocolRow) {
  const completedAt = protocol.completed_at ?? protocol.submitted_at;
  // Drafts can already be active; only a published plan is ready for the owner.
  const ready = protocol.published === 1 && (protocol.status === 'active' || protocol.status === 'paused');
  const named = protocol.status === 'completed' || ready;
  const submitted = !!completedAt && !named;
  const paused = ready && protocol.status === 'paused';
  // The Protocol tab shows one plan per horse. A paused plan behind another active or paused plan keeps
  // its notice, but offers no link that would silently open that other plan.
  const canOpen = paused ? protocol.shown_in_tab === 1 : !submitted && (!!completedAt || protocol.status !== 'completed');
  return { completedAt, ready, named, submitted, paused, canOpen };
}

/**
 * One card per purchased protocol and its intake. By default for the selected
 * horse; with `allHorses` for every active horse of the user (Home), so intakes
 * of several horses each get their own card.
 * With `actionableOnly` (below a completed plan on Vandaag) only intakes and plans the owner can still
 * open, never another completed plan.
 */
export function PurchasedProtocols({ allHorses = false, excludeProtocolIds = [], actionableOnly = false }: { allHorses?: boolean; excludeProtocolIds?: string[]; actionableOnly?: boolean }) {
  const horseId = useCurrentHorseId(), userId = useCurrentUserId();
  const { selectHorse } = useDb();
  const { data } = useQuery<PurchasedProtocolRow>(allHorses ? PROTOCOLS_FOR_ALL_HORSES : PROTOCOLS_FOR_HORSE, allHorses ? [userId] : [horseId, userId]);
  const visible = data.filter(protocol => !excludeProtocolIds.includes(protocol.id)
    && (!actionableOnly || (protocol.status !== 'completed' && cardState(protocol).canOpen)));
  if (!visible.length) return null;
  return <View className="mb-4 gap-3">
    {visible.map(protocol => {
      const { completedAt, named, submitted, paused, canOpen } = cardState(protocol);
      const horseName = protocol.horse_name || 'je paard';
      const possessive = protocol.horse_name ? horsePossessive(protocol.horse_name) : 'Je';
      const generic = protocol.title === 'Persoonlijk protocol';
      const partial = !completedAt && !named && !!protocol.has_answers;
      const action = paused ? 'Bekijk je herstelplan' : completedAt ? 'Intake bekijken' : partial ? 'Ga verder met je intake' : 'Start de intake';
      return <Pressable key={protocol.id} accessibilityRole={canOpen ? 'button' : undefined} accessibilityLabel={canOpen ? `${action} voor ${horseName}` : undefined} disabled={!canOpen}
        onPress={() => {
          selectHorse(protocol.horse_id);
          if (paused) router.push({ pathname: '/(tabs)/(pager)/protocol', params: { horseId: protocol.horse_id, protocolId: protocol.id, tab: 'vandaag' } });
          else router.push({ pathname: '/intake', params: { bookingId: protocol.booking_id } });
        }} className={`gap-3 ${PLAIN_CARD}`}>
      {/* With several horses a custom title alone does not say which horse it is for. */}
      {allHorses && !generic && named && !paused && <Text className="-mb-2 font-semi text-[14px] text-ink-70">{horseName}</Text>}
      <Text className={named && !paused ? 'font-bold text-[20px] leading-[26px] text-ink' : HOME_NOTICE_LABEL}>{paused || submitted ? `${possessive} EquiBoost traject` : named ? (generic ? `Herstelplan van ${horseName}` : protocol.title) : `${possessive} intake`}</Text>
      {(!named || paused) && <Text className="font-bold text-[20px] leading-[26px] text-ink">{paused ? `${possessive} herstelplan is gepauzeerd` : submitted ? `${possessive} intake is verstuurd!` : partial ? `Je bent al begonnen met ${protocol.horse_name ? `${possessive} intake` : 'de intake voor je paard'}!` : 'Welkom bij je EquiBoost traject!'}</Text>}
      <Text className={HOME_NOTICE_BODY}>{paused ? 'Je herstelplan staat momenteel op pauze. Zodra het weer wordt hervat, kun je verdergaan waar je gebleven was.'
        : submitted ? `We gaan met alle informatie aan de slag om een herstelplan voor ${horseName} op te stellen.`
        : partial ? 'Je bent goed op weg! Maak de intake verder af, zodat we zo snel mogelijk aan de slag kunnen met je herstelplan.'
        : !completedAt && !named
        ? `We gaan aan de slag met de gezondheid van ${horseName}. Vul de intake zo goed en volledig mogelijk in, zodat we een compleet beeld krijgen en gericht aan de slag kunnen.`
        : protocol.status === 'completed' ? 'Afgerond' : 'Je herstelplan staat klaar.'}</Text>
      {submitted && <Text className="font-semi text-[14px] leading-[22px] text-ink">Binnen 5 werkdagen staat het herstelplan hier voor je klaar.</Text>}
      {!!completedAt && !submitted && !paused && <Text className="text-sm text-ink-70">Intake ingestuurd op {formatIntakeDate(completedAt)}</Text>}
      {canOpen && <Text className={`mt-1 ${HOME_NOTICE_LINK}`}>{action} →</Text>}
    </Pressable>; })}
  </View>;
}
