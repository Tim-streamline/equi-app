export type LocalAttachment = {
  id: string; userId: string; bookingId: string; horseId: string;
  section: string; field: string; name: string; mime: string;
  uri?: string; blob?: Blob; uploaded: boolean; error?: string;
};
export type AttachmentAsset = { uri: string; file?: File; name: string; mime: string };
export type AttachmentStorage = {
  get(userId: string, id: string): Promise<LocalAttachment | null>;
  put(record: LocalAttachment): Promise<void>;
};
