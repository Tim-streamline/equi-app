import { ReactNode, useMemo } from 'react';
import { Image, Pressable, Text, View } from 'react-native';
import { ExternalLink, Headphones } from 'lucide-react-native';
import { useVideoPlayer, VideoView } from 'expo-video';
import * as WebBrowser from 'expo-web-browser';

type MarkdownBlock =
  | { type: 'heading'; level: number; text: string }
  | { type: 'paragraph'; text: string }
  | { type: 'list'; ordered: boolean; items: string[] }
  | { type: 'quote'; text: string }
  | { type: 'code'; text: string }
  | { type: 'image'; alt: string; url: string }
  | { type: 'video'; url: string }
  | { type: 'audio'; url: string }
  | { type: 'divider' };

const API_BASE = (process.env.EXPO_PUBLIC_API_BASE_URL ?? '').replace(/\/$/, '');

function safeMediaUrl(value: string) {
  const url = value.trim();
  if (/^https?:\/\//i.test(url)) return url;
  if (url.startsWith('/') && API_BASE) return `${API_BASE}${url}`;
  return '';
}

function stripInlineHtml(value: string) {
  return value
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/?(?:strong|b)>/gi, '**')
    .replace(/<\/?(?:em|i)>/gi, '*')
    .replace(/<[^>]+>/g, '');
}

export function parseMarkdown(sourceValue: string): MarkdownBlock[] {
  const media: Extract<MarkdownBlock, { type: 'video' | 'audio' }>[] = [];
  const source = String(sourceValue ?? '')
    .replace(/\r\n?/g, '\n')
    .replace(/<(video|audio)\b[^>]*\bsrc=["']([^"']+)["'][^>]*>[\s\S]*?<\/\1>/gi, (_, kind, url) => {
      const index = media.push({ type: String(kind).toLowerCase() as 'video' | 'audio', url: safeMediaUrl(url) }) - 1;
      return `\n\n@@MEDIA:${index}@@\n\n`;
    })
    .trim();

  if (!source) return [];

  const blocks: MarkdownBlock[] = [];
  const lines = source.split('\n');
  let paragraph: string[] = [];
  const flushParagraph = () => {
    const text = stripInlineHtml(paragraph.join(' ')).trim();
    if (text) blocks.push({ type: 'paragraph', text });
    paragraph = [];
  };

  for (let index = 0; index < lines.length;) {
    const line = lines[index];
    const trimmed = line.trim();

    if (!trimmed) {
      flushParagraph();
      index += 1;
      continue;
    }

    const mediaMatch = trimmed.match(/^@@MEDIA:(\d+)@@$/);
    if (mediaMatch) {
      flushParagraph();
      const block = media[Number(mediaMatch[1])];
      if (block?.url) blocks.push(block);
      index += 1;
      continue;
    }

    if (/^```/.test(trimmed)) {
      flushParagraph();
      const code: string[] = [];
      index += 1;
      while (index < lines.length && !/^```/.test(lines[index].trim())) {
        code.push(lines[index]);
        index += 1;
      }
      if (index < lines.length) index += 1;
      blocks.push({ type: 'code', text: code.join('\n') });
      continue;
    }

    const heading = trimmed.match(/^(#{1,6})\s+(.+)$/);
    if (heading) {
      flushParagraph();
      blocks.push({ type: 'heading', level: heading[1].length, text: stripInlineHtml(heading[2]).trim() });
      index += 1;
      continue;
    }

    if (/^(?:-{3,}|\*{3,}|_{3,})$/.test(trimmed)) {
      flushParagraph();
      blocks.push({ type: 'divider' });
      index += 1;
      continue;
    }

    const image = trimmed.match(/^!\[([^\]]*)]\(([^)]+)\)$/);
    if (image) {
      flushParagraph();
      const url = safeMediaUrl(image[2]);
      if (url) blocks.push({ type: 'image', alt: image[1], url });
      index += 1;
      continue;
    }

    if (/^>\s?/.test(trimmed)) {
      flushParagraph();
      const quote: string[] = [];
      while (index < lines.length && /^>\s?/.test(lines[index].trim())) {
        quote.push(lines[index].trim().replace(/^>\s?/, ''));
        index += 1;
      }
      blocks.push({ type: 'quote', text: stripInlineHtml(quote.join(' ')).trim() });
      continue;
    }

    const listItem = trimmed.match(/^(?:([-*+])|(\d+)\.)\s+(.+)$/);
    if (listItem) {
      flushParagraph();
      const ordered = !!listItem[2];
      const items: string[] = [];
      while (index < lines.length) {
        const match = lines[index].trim().match(/^(?:([-*+])|(\d+)\.)\s+(.+)$/);
        if (!match || !!match[2] !== ordered) break;
        items.push(stripInlineHtml(match[3]).trim());
        index += 1;
      }
      blocks.push({ type: 'list', ordered, items });
      continue;
    }

    paragraph.push(trimmed);
    index += 1;
  }

  flushParagraph();
  return blocks;
}

function InlineMarkdown({ children, color = '#1B2A2A' }: { children: string; color?: string }) {
  const source = stripInlineHtml(children);
  const pattern = /(\*\*[^*]+\*\*|__[^_]+__|`[^`]+`|\[[^\]]+]\([^)]+\)|\*[^*]+\*|_[^_]+_)/g;
  const nodes: ReactNode[] = [];
  let cursor = 0;

  for (const match of source.matchAll(pattern)) {
    const index = match.index ?? 0;
    if (index > cursor) nodes.push(source.slice(cursor, index));
    const token = match[0];

    if (token.startsWith('**') || token.startsWith('__')) {
      nodes.push(<Text key={index} className="font-bold">{token.slice(2, -2)}</Text>);
    } else if (token.startsWith('`')) {
      nodes.push(
        <Text key={index} className="font-medium text-teal-700" style={{ backgroundColor: '#EAFBF9' }}>
          {token.slice(1, -1)}
        </Text>,
      );
    } else if (token.startsWith('[')) {
      const link = token.match(/^\[([^\]]+)]\(([^)]+)\)$/);
      const url = safeMediaUrl(link?.[2] ?? '');
      nodes.push(
        <Text
          key={index}
          className="font-semi text-teal-500 underline"
          onPress={url ? () => WebBrowser.openBrowserAsync(url) : undefined}
        >
          {link?.[1] ?? token}
        </Text>,
      );
    } else {
      nodes.push(<Text key={index} style={{ fontStyle: 'italic' }}>{token.slice(1, -1)}</Text>);
    }
    cursor = index + token.length;
  }

  if (cursor < source.length) nodes.push(source.slice(cursor));
  return <Text style={{ color }}>{nodes}</Text>;
}

function EmbeddedVideo({ url }: { url: string }) {
  const player = useVideoPlayer(url);

  return (
    <View className="mb-5 aspect-video overflow-hidden rounded-2xl bg-teal-800">
      <VideoView
        player={player}
        nativeControls
        contentFit="contain"
        fullscreenOptions={{ enable: true, orientation: 'landscape' }}
        style={{ width: '100%', height: '100%' }}
      />
    </View>
  );
}

function EmbeddedAudioLink({ url }: { url: string }) {
  return (
    <Pressable
      accessibilityRole="link"
      onPress={() => WebBrowser.openBrowserAsync(url)}
      className="mb-5 flex-row items-center gap-3 rounded-2xl bg-mint-50 px-4 py-3.5 active:bg-mint-100"
    >
      <Headphones size={20} color="#108A82" />
      <Text className="flex-1 font-semi text-[14px] text-mint-800">Beluister audio</Text>
      <ExternalLink size={16} color="#108A82" />
    </Pressable>
  );
}

export function MarkdownBody({ markdown }: { markdown: string }) {
  const blocks = useMemo(() => parseMarkdown(markdown), [markdown]);

  return (
    <View>
      {blocks.map((block, index) => {
        if (block.type === 'heading') {
          const large = block.level === 1;
          return (
            <Text
              key={index}
              className="font-bold text-teal-700"
              style={{ fontSize: large ? 22 : block.level === 2 ? 19 : 17, lineHeight: large ? 28 : 24, marginTop: 24, marginBottom: 8 }}
            >
              <InlineMarkdown color="#0D5C5B">{block.text}</InlineMarkdown>
            </Text>
          );
        }

        if (block.type === 'paragraph') {
          return (
            <Text key={index} className="mb-4 text-[15.5px] leading-[25px] text-ink-70">
              <InlineMarkdown color="rgba(27,42,42,0.78)">{block.text}</InlineMarkdown>
            </Text>
          );
        }

        if (block.type === 'list') {
          return (
            <View key={index} className="mb-4 gap-2">
              {block.items.map((item, itemIndex) => (
                <View key={itemIndex} className="flex-row items-start gap-2.5">
                  <Text className="w-4 font-semi text-[15px] leading-[24px] text-mint-700">
                    {block.ordered ? `${itemIndex + 1}.` : '•'}
                  </Text>
                  <Text className="flex-1 text-[15.5px] leading-[24px] text-ink-70">
                    <InlineMarkdown color="rgba(27,42,42,0.78)">{item}</InlineMarkdown>
                  </Text>
                </View>
              ))}
            </View>
          );
        }

        if (block.type === 'quote') {
          return (
            <View key={index} className="mb-5 flex-row overflow-hidden rounded-r-xl bg-mint-50">
              <View className="w-1 bg-mint-500" />
              <Text className="flex-1 px-4 py-3.5 text-[15px] leading-[23px] text-mint-800" style={{ fontStyle: 'italic' }}>
                <InlineMarkdown color="#0E6F69">{block.text}</InlineMarkdown>
              </Text>
            </View>
          );
        }

        if (block.type === 'code') {
          return (
            <View key={index} className="mb-5 rounded-xl bg-teal-800 p-4">
              <Text className="text-[13px] leading-[20px] text-mint-100" style={{ fontFamily: 'monospace' }}>{block.text}</Text>
            </View>
          );
        }

        if (block.type === 'image') {
          return (
            <View key={index} className="mb-5 overflow-hidden rounded-2xl bg-ink-8">
              <Image source={{ uri: block.url }} accessibilityLabel={block.alt || undefined} style={{ width: '100%', height: 220 }} resizeMode="cover" />
            </View>
          );
        }

        if (block.type === 'video') return <EmbeddedVideo key={index} url={block.url} />;

        if (block.type === 'audio') return <EmbeddedAudioLink key={index} url={block.url} />;

        return <View key={index} className="my-5 h-px bg-ink-8" />;
      })}
    </View>
  );
}
