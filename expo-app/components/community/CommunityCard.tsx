import { useState } from 'react';
import { Alert, Pressable, Share, Text, TextInput, View } from 'react-native';
import { router } from 'expo-router';
import * as Linking from 'expo-linking';
import { Bookmark, MessageCircle, MoreHorizontal, ThumbsUp } from 'lucide-react-native';
import { Avatar } from '@/components/ui/Avatar';
import { Chip } from '@/components/ui/Chip';
import { communityRequest } from '@/hooks/useCommunity';
import { contentActions, relativeTime, type CommunityPost, type CommunityReply } from '@/lib/community';
import { Action, CommunityDialog } from './Common';
import { MediaGallery } from './MediaGallery';

const reasons = [{ id: 'spam', label: 'Spam' }, { id: 'abuse', label: 'Ongepast of kwetsend' }, { id: 'medical_risk', label: 'Mogelijk onveilig advies' }, { id: 'duplicate', label: 'Dubbel bericht' }, { id: 'other', label: 'Anders' }];
const labels: Record<string, string> = { edit: 'Bewerken', delete: 'Verwijderen', share: 'Delen', report: 'Rapporteren', mute: 'Auteur dempen' };

export function CommunityCard({ item, postId, canParticipate, locked = false, detail = false, onChange, onReply }: {
  item: CommunityPost | CommunityReply; postId: string; canParticipate: boolean; locked?: boolean; detail?: boolean;
  onChange: () => void | Promise<void>; onReply?: (reply: CommunityReply) => void;
}) {
  const post = 'media' in item ? item : null;
  const reply = 'parentReplyId' in item ? item : null;
  const [menu, setMenu] = useState(false);
  const [report, setReport] = useState(false);
  const [reason, setReason] = useState('spam');
  const [reportDetail, setReportDetail] = useState('');
  const [edit, setEdit] = useState(false);
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const target = `/api/community/${post ? 'posts' : 'replies'}/${item.id}`;
  const run = async (fn: () => Promise<unknown>) => {
    if (busy) return;
    setBusy(true);
    try { await fn(); await onChange(); }
    catch (error) { Alert.alert('Niet gelukt', error instanceof Error ? error.message : 'Probeer opnieuw.'); }
    finally { setBusy(false); }
  };
  const openThread = () => { if (post && !detail) router.push({ pathname: '/(tabs)/community/thread/[id]', params: { id: postId } }); };
  const act = (action: string) => {
    setMenu(false);
    if (action === 'edit') {
      if (post) router.push({ pathname: '/(tabs)/community/compose', params: { id: item.id } });
      else { setBody(item.body); setEdit(true); }
    }
    if (action === 'delete') Alert.alert('Bericht verwijderen?', post ? 'Dit verwijdert ook alle reacties en bijlagen.' : 'Deze reactie wordt verwijderd.', [
      { text: 'Annuleren', style: 'cancel' }, { text: 'Verwijderen', style: 'destructive', onPress: () => void run(() => communityRequest(target, 'DELETE')) },
    ]);
    if (action === 'share') void Share.share({ message: Linking.createURL(`/community/thread/${postId}`) }).catch(() => {});
    if (action === 'report') setReport(true);
    if (action === 'mute') {
      const type = item.authorTherapistId ? 'therapist' : 'user';
      const id = item.authorTherapistId || item.authorUserId;
      if (id) Alert.alert(`${item.authorName} dempen?`, 'Je ziet berichten en reacties van deze auteur niet meer. Je kunt dit in Community weer uitzetten.', [
        { text: 'Annuleren', style: 'cancel' }, { text: 'Dempen', onPress: () => void run(() => communityRequest(`/api/community/mutes/${type}/${id}`, 'PUT')) },
      ]);
    }
  };

  return <View className={`rounded-2xl border p-4 ${item.authorIsExpert ? 'border-mint-100 bg-mint-50' : 'border-ink-8 bg-white'}`}>
    <View className="mb-3 flex-row items-center gap-2.5">
      <Avatar initial={item.authorInitial} size={36} gradient={false} bg={item.authorIsExpert ? '#0D5C5B' : '#30BCAF'} />
      <View className="flex-1">
        <View className="flex-row flex-wrap items-center gap-2"><Text className="font-semi text-[15px] text-ink">{item.authorName}</Text>{item.authorIsExpert && <Chip label="Therapeut" />}</View>
        <Text className="text-[12px] text-ink-50">{relativeTime(item.createdAt)}{item.editedAt ? ' · bewerkt' : ''}{post?.category ? ` · ${post.category.label}` : ''}</Text>
      </View>
      <Pressable accessibilityRole="button" accessibilityLabel="Berichtacties" onPress={() => setMenu(true)} hitSlop={12}><MoreHorizontal size={22} color="#466362" /></Pressable>
    </View>
    {post && (post.pinned || post.locked) && <View className="mb-3 flex-row gap-2">{post.pinned && <Chip label="Vastgezet" />}{post.locked && <Chip label="Gesloten" variant="warn" />}</View>}
    {reply?.parentReplyId && <View className="mb-3 rounded-xl border-l-2 border-mint-500 bg-canvas p-3">
      <Text className="font-semi text-[12px] text-mint-700">Antwoord op {reply.parentReply?.authorName ?? 'een niet-beschikbare reactie'}</Text>
      {reply.parentReply && <Text className="mt-1 text-[13px] text-ink-70" numberOfLines={3}>{reply.parentReply.body}</Text>}
    </View>}
    <Pressable disabled={!post || detail} onPress={openThread} accessibilityRole={post && !detail ? 'button' : undefined}>
      <Text className="text-[15px] leading-[22px] text-ink" numberOfLines={detail ? undefined : 7}>{item.body}</Text>
    </Pressable>
    {post && <>
      {!!post.tags.length && <View className="mt-3 flex-row flex-wrap gap-1.5">{post.tags.map(tag => <Chip key={tag.id} label={tag.label} variant="outline" />)}</View>}
      <MediaGallery media={post.media} />
      {!detail && post.expertReply && <Pressable onPress={openThread} className="mt-3 rounded-xl bg-mint-50 p-3">
        <Text className="font-semi text-[12px] text-teal-700">✓ {post.expertReply.authorName} reageerde · Therapeut</Text>
        <Text className="mt-1 text-[14px] leading-5 text-teal-700" numberOfLines={3}>{post.expertReply.body}</Text>
      </Pressable>}
    </>}
    <View className="mt-4 flex-row items-center gap-5 border-t border-ink-8 pt-3">
      <Pressable accessibilityRole="button" accessibilityLabel={item.liked ? 'Like verwijderen' : 'Vind ik leuk'} accessibilityState={{ selected: item.liked, disabled: busy || (!canParticipate && !item.liked) }} disabled={busy || (!canParticipate && !item.liked)}
        onPress={() => void run(() => communityRequest(`${target}/like`, item.liked ? 'DELETE' : 'PUT'))} className="flex-row items-center gap-1.5 py-1">
        <ThumbsUp size={17} color={item.liked ? '#127A79' : '#718580'} fill={item.liked ? '#CDEEE7' : 'transparent'} /><Text className="text-[13px] text-ink-70">{item.likesCount}</Text>
      </Pressable>
      {post ? <Pressable accessibilityRole="button" accessibilityLabel="Open reacties" onPress={openThread} disabled={detail} className="flex-row items-center gap-1.5 py-1"><MessageCircle size={17} color="#718580" /><Text className="text-[13px] text-ink-70">{post.repliesCount} reacties</Text></Pressable>
        : canParticipate && !locked && <Pressable accessibilityRole="button" onPress={() => reply && onReply?.(reply)}><Text className="font-semi text-[13px] text-mint-700">Antwoorden</Text></Pressable>}
      {post && <Pressable accessibilityRole="button" accessibilityLabel={post.bookmarked ? 'Uit bewaard verwijderen' : 'Bericht bewaren'} accessibilityState={{ selected: post.bookmarked }} disabled={busy} onPress={() => void run(() => communityRequest(`${target}/bookmark`, post.bookmarked ? 'DELETE' : 'PUT'))} style={{ marginLeft: 'auto', padding: 4 }}>
        <Bookmark size={20} color="#127A79" fill={post.bookmarked ? '#BDE8DD' : 'transparent'} />
      </Pressable>}
    </View>
    <CommunityDialog open={menu} close={() => setMenu(false)} title="Berichtacties">
      {contentActions(item.owned, canParticipate, locked).filter(action => action !== 'mute' || !!(item.authorUserId || item.authorTherapistId)).map(action => <Action key={action} label={labels[action]} disabled={busy} onPress={() => act(action)} />)}
    </CommunityDialog>
    <CommunityDialog open={report} close={() => { if (!busy) setReport(false); }} title="Bericht rapporteren">
      <Text className="text-ink-70">Waarom wil je dit bericht melden? Een moderator bekijkt je melding.</Text>
      {reasons.map(option => <Pressable key={option.id} accessibilityRole="radio" accessibilityState={{ checked: reason === option.id }} onPress={() => setReason(option.id)} className={`rounded-xl border p-3 ${reason === option.id ? 'border-mint-500 bg-mint-50' : 'border-ink-8'}`}><Text className="text-ink">{option.label}</Text></Pressable>)}
      <TextInput accessibilityLabel="Toelichting bij melding" value={reportDetail} onChangeText={setReportDetail} placeholder="Toelichting (optioneel)" multiline maxLength={2000} className="min-h-24 rounded-xl bg-white p-4 text-ink" />
      <Action label={busy ? 'Versturen…' : 'Melding versturen'} disabled={busy} primary onPress={() => void run(async () => { await communityRequest(`${target}/report`, 'POST', { reason, detail: reportDetail }); setReport(false); Alert.alert('Melding ontvangen', 'Dank je. Je melding staat bij de moderators.'); })} />
    </CommunityDialog>
    <CommunityDialog open={edit} close={() => { if (!busy) setEdit(false); }} title="Reactie bewerken">
      <TextInput accessibilityLabel="Reactie" value={body} onChangeText={setBody} multiline maxLength={10000} className="min-h-40 rounded-xl bg-white p-4 text-ink" />
      <Action label={busy ? 'Opslaan…' : 'Opslaan'} disabled={busy || !body.trim()} primary onPress={() => void run(async () => { await communityRequest(target, 'PATCH', { body }); setEdit(false); })} />
    </CommunityDialog>
  </View>;
}
