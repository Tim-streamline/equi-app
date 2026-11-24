import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';
import { usePreventRemove } from '@react-navigation/native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router, useLocalSearchParams } from 'expo-router';
import { KeyboardTextInput, KeyboardScrollView, KeyboardViewport } from '@/components/ui/KeyboardForm';
import { SubHeader } from '@/components/ui/SubHeader';
import { Button } from '@/components/ui/Button';
import { dashboardRequest } from '@/hooks/useHorseDashboard';
import { accountSession } from '@/lib/account-session';
import { reconcileEvaluation, evaluationComplete, evaluationDate, evaluationMoney, horsePossessive, type ProtocolEvaluationData } from '@/lib/protocol-evaluation';
import { HOME_NOTICE_BODY, HOME_NOTICE_LINK, HOME_NOTICE_LABEL, PLAIN_CARD } from '@/components/ui/styles';

export default function ProtocolEvaluationScreen() {
  const { protocolId } = useLocalSearchParams<{ protocolId: string }>();
  return <EvaluationForm key={protocolId} protocolId={protocolId} />;
}

function EvaluationForm({ protocolId }: { protocolId: string }) {
  const [conflicts, setConflicts] = useState<Record<string, string>>({});
  const [data, setData] = useState<ProtocolEvaluationData | null>(null);
  const [answers, setAnswers] = useState<Record<string, string>>({});
  const [offer, setOffer] = useState(false), [busy, setBusy] = useState(false);
  const [error, setError] = useState(''), [notice, setNotice] = useState(''), [retry, setRetry] = useState(0);
  const pending = useRef(false), generation = useRef(0);
  usePreventRemove(busy, () => {});
  useEffect(() => {
    const current = ++generation.current;
    setError(''); setNotice(''); setData(null); setAnswers({}); setOffer(false);
    if (!protocolId) { setError('Open de evaluatie vanuit je herstelplan.'); return; }
    void dashboardRequest(`/api/protocols/${protocolId}/evaluation`).then((result: ProtocolEvaluationData) => {
      if (generation.current === current) { setData(result); setAnswers(result.answers ?? {}); }
    }).catch(() => { if (generation.current === current) setError('De evaluatie kon niet worden geladen. Controleer je verbinding of probeer het later opnieuw.'); });
    return () => { generation.current++; };
  }, [protocolId, retry]);
  async function save(submit: boolean) {
    if (!data || pending.current) return;
    if (submit && !evaluationComplete(data.questions, answers)) { setError('Beantwoord alle vragen voordat je de evaluatie verstuurt.'); return; }
    const revision = accountSession.revision, current = generation.current;
    pending.current = true; setBusy(true); setError(''); setNotice('');
    try {
      const result = await dashboardRequest(`/api/protocols/${protocolId}/evaluation`, { answers, submit, revision: data.revision, question_version: data.questionVersion });
      if (revision === accountSession.revision && current === generation.current) {
        setData(result); setConflicts({}); setNotice(submit ? 'Je evaluatie is verstuurd naar je therapeut.' : 'Je concept is opgeslagen. Je kunt later verdergaan.');
        if (submit) setOffer(true);
      }
    } catch (error) {
      const currentForm = () => current === generation.current && revision === accountSession.revision;
      if (!currentForm()) return;
      if (String(error).includes('(412)')) {
        setError('Er is al een nieuwere versie van deze evaluatie opgeslagen. Je eigen antwoorden staan nog op dit scherm.');
        try {
          const latest: ProtocolEvaluationData = await dashboardRequest(`/api/protocols/${protocolId}/evaluation`);
          if (!currentForm()) return;
          if (latest.questionVersion === data.questionVersion) {
            const merged = reconcileEvaluation(data.answers, answers, latest.answers);
            setAnswers(merged.answers); setConflicts(merged.conflicts); setData(latest);
            setError('De laatst opgeslagen antwoorden zijn opgehaald. Controleer je antwoorden en sla opnieuw op.');
          }
        } catch {
          if (currentForm()) setError('De nieuwste antwoorden konden niet worden opgehaald. Je eigen antwoorden staan nog op dit scherm. Probeer opnieuw op te slaan.');
        }
      } else {
        setError(String(error).includes('409')
          ? 'De vragen zijn intussen aangepast. Kopieer je antwoorden voordat je het formulier opnieuw opent. Je antwoorden staan nog op dit scherm.'
          : 'Opslaan is niet gelukt. Je antwoorden staan nog op dit scherm. Probeer het opnieuw.');
      }
    }
    finally { pending.current = false; if (current === generation.current) setBusy(false); }
  }
  return <SafeAreaView className="flex-1 bg-canvas">
    <SubHeader title={offer ? 'Vervolg van je EquiBoost traject' : 'Evaluatie herstelplan'} onBack={() => {
      if (pending.current) return;
      if (offer) setOffer(false);
      else if (router.canGoBack()) router.back();
      else router.replace('/(tabs)/(pager)/home');
    }} />
    <KeyboardViewport><KeyboardScrollView contentContainerStyle={{ padding: 20, paddingBottom: 48 }} keyboardShouldPersistTaps="handled">
      {!!error && <View accessibilityRole="alert" className="mb-4 rounded-xl bg-[#FFF0EC] p-4"><Text className={HOME_NOTICE_BODY}>{error}</Text>{!data && <Button title="Opnieuw proberen" onPress={() => setRetry(n => n + 1)} />}</View>}
      {!!notice && <View accessibilityRole="alert" className="mb-4 rounded-xl bg-mint-50 p-4"><Text className={HOME_NOTICE_BODY}>{notice}</Text></View>}
      {!data && !error && <ActivityIndicator color="#18BAB0" />}
      {data && !offer && <View className="gap-4">
        <Text className={HOME_NOTICE_LABEL}>{horsePossessive(data.horseName)} EquiBoost traject</Text>
        <Text className="font-bold text-[22px] leading-[28px] text-ink">Hoe gaat het nu met {data.horseName}?</Text>
        <Text className={HOME_NOTICE_BODY}>{data.completed ? 'Je herstelplan is afgerond.' : `Je huidige herstelplan loopt tot en met ${evaluationDate(data.endsOn)}.`} Vertel wat er is veranderd en hoe het uitvoeren van de adviezen is gegaan. Zo kan je therapeut met je meedenken over passende vervolgstappen.</Text>
        {Object.entries(data.questions).map(([key, question], index) => <View key={key} className={`gap-3 ${PLAIN_CARD}`}>
          <Text className="font-semi text-[16px] leading-[23px] text-ink">{index + 1}. {question}</Text>
          {key in conflicts && <Text className={HOME_NOTICE_BODY}>Laatst opgeslagen antwoord: {conflicts[key] || '(leeg)'}</Text>}
          <KeyboardTextInput accessibilityLabel={question} editable={!busy} multiline numberOfLines={5} textAlignVertical="top" maxLength={5000}
            value={answers[key] ?? ''} onChangeText={value => setAnswers(previous => ({ ...previous, [key]: value }))}
            className="min-h-[120px] rounded-xl border border-ink-8 bg-canvas p-3 font-sans text-[15px] leading-[23px] text-ink" />
        </View>)}
        <Text className="font-sans text-[13px] leading-5 text-ink-70">{data.submittedAt ? 'Je evaluatie is verstuurd. Vul je antwoorden gerust aan en verstuur ze opnieuw om de wijzigingen met je therapeut te delen.' : 'Je kunt eerst een concept opslaan. Pas bij versturen worden je antwoorden zichtbaar voor je therapeut.'}</Text>
        <Button title={busy ? 'Opslaan…' : data.submittedAt ? 'Evaluatie opnieuw versturen' : 'Verstuur mijn evaluatie'} disabled={busy} onPress={() => void save(true)} />
        {!data.submittedAt && <Button title="Concept opslaan" variant="ghost" disabled={busy} onPress={() => void save(false)} />}
        {!!data.submittedAt && <Pressable accessibilityRole="button" onPress={() => setOffer(true)} className="min-h-[44px] justify-center"><Text className={HOME_NOTICE_LINK}>Bekijk de mogelijkheden voor een vervolg →</Text></Pressable>}
      </View>}
      {data && offer && <View className="gap-5">
        <View className="gap-3 rounded-[22px] bg-[#0D5C5B] p-5">
          <Text className="font-semi text-[12px] uppercase tracking-[1px] text-[#99E8DF]">Samen verder met {data.horseName}</Text>
          <Text className="font-bold text-[24px] leading-[30px] text-white">Een volgende stap in {horsePossessive(data.horseName)} herstel</Text>
          <Text className="font-sans text-[15px] leading-[23px] text-white">Wil je verder bouwen op wat je hebt bereikt? Bespreek een nieuw persoonlijk herstelplan met opnieuw drie maanden EquiBoost begeleiding.</Text>
        </View>
        <View className={`gap-3 ${PLAIN_CARD}`}>
          <Text className="font-bold text-[19px] text-ink">Opnieuw gericht aan de slag</Text>
          <Text className={HOME_NOTICE_BODY}>Je evaluatie vormt de basis voor het gesprek met je therapeut: hoe gaat het nu, wat vraagt nog aandacht en welke vervolgstappen passen bij jouw paard?</Text>
          <Text className={HOME_NOTICE_BODY}>Bij een verlenging krijg je een nieuw persoonlijk herstelplan en drie maanden begeleiding, afgestemd op de actuele situatie.</Text>
        </View>
        <View className="gap-3 rounded-[22px] bg-mint-50 p-5">
          <Text className="font-bold text-[19px] text-ink">{data.discountEligible ? '10% korting op je verlenging' : 'Je volgende EquiBoost traject'}</Text>
          <Text className={HOME_NOTICE_BODY}>{data.discountEligible
            ? `Verleng je uiterlijk ${evaluationDate(data.discountUntil)}, binnen één week na afloop van je huidige herstelplan? Dan krijg je 10% korting op de geldende EquiBoost-prijs.`
            : `De kortingsperiode tot en met ${evaluationDate(data.discountUntil)} is afgelopen. Een volgend traject is mogelijk tegen de geldende EquiBoost-prijs.`}</Text>
          {data.discountEligible && <Text className="font-sans text-[15px] text-ink-70 line-through">{evaluationMoney(data.basePriceCents)}</Text>}
          <Text className="font-bold text-[26px] text-teal-700">{evaluationMoney(data.priceCents)} <Text className="font-sans text-[14px] text-ink">voor 3 maanden</Text></Text>
        </View>
        <Text className={HOME_NOTICE_BODY}>Verlengen en betalen kan nog niet via de app. Bespreek het vervolg met je therapeut. Via dit scherm wordt geen aankoop of nieuw traject gestart.</Text>
        <Text className={HOME_NOTICE_BODY}>Neem hiervoor op de gebruikelijke manier contact op met je therapeut en vermeld dat je wilt verlengen.</Text>
        <Button title="Terug naar Home" onPress={() => router.replace('/(tabs)/(pager)/home')} />
        <Pressable accessibilityRole="button" onPress={() => setOffer(false)} className="min-h-[44px] justify-center"><Text className={HOME_NOTICE_LINK}>Bekijk of wijzig je evaluatie →</Text></Pressable>
      </View>}
    </KeyboardScrollView></KeyboardViewport>
  </SafeAreaView>;
}
