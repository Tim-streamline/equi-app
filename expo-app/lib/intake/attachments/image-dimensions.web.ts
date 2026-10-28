/** Read dimensions from headers without decoding a full-resolution image. */
export async function readPhotoDimensions(file: Blob): Promise<{ width: number; height: number }> {
  const invalid = () => new Error('De foto kon niet worden gelezen. Kies een JPG-, PNG- of WebP-foto.');
  const read = async (offset: number, length: number) => {
    const bytes = await file.slice(offset, offset + length).arrayBuffer();
    if (bytes.byteLength !== length) throw invalid();
    return new DataView(bytes);
  };
  const header = await read(0, Math.min(file.size, 24));
  const text = (view: DataView, start: number, length: number) =>
    Array.from({ length }, (_, i) => String.fromCharCode(view.getUint8(start + i))).join('');
  if (header.byteLength >= 24 && header.getUint32(0) === 0x89504e47 && header.getUint32(4) === 0x0d0a1a0a
    && text(header, 12, 4) === 'IHDR') {
    return { width: header.getUint32(16), height: header.getUint32(20) };
  }
  if (header.byteLength >= 2 && header.getUint16(0) === 0xffd8) {
    let offset = 2;
    while (offset + 4 <= file.size) {
      const marker = await read(offset, 4);
      if (marker.getUint8(0) !== 0xff) throw invalid();
      const type = marker.getUint8(1);
      if (type === 0xff) { offset++; continue; }
      const length = marker.getUint16(2);
      if (length < 2 || offset + 2 + length > file.size || type === 0xda || type === 0xd9) throw invalid();
      if ([0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf].includes(type)) {
        if (length < 8) throw invalid();
        const frame = await read(offset, 9);
        return { width: frame.getUint16(7), height: frame.getUint16(5) };
      }
      offset += 2 + length;
    }
  }
  if (header.byteLength >= 12 && text(header, 0, 4) === 'RIFF' && text(header, 8, 4) === 'WEBP') {
    let offset = 12;
    while (offset + 8 <= file.size) {
      const chunk = await read(offset, 8);
      const type = text(chunk, 0, 4), length = chunk.getUint32(4, true);
      if (offset + 8 + length > file.size) throw invalid();
      if (type === 'VP8X' && length >= 10) {
        const frame = await read(offset + 8, 10);
        const uint24 = (start: number) => frame.getUint8(start) + (frame.getUint8(start + 1) << 8) + (frame.getUint8(start + 2) << 16);
        return { width: uint24(4) + 1, height: uint24(7) + 1 };
      }
      if (type === 'VP8L' && length >= 5) {
        const frame = await read(offset + 8, 5);
        if (frame.getUint8(0) !== 0x2f) throw invalid();
        const bits = frame.getUint32(1, true);
        return { width: (bits & 0x3fff) + 1, height: ((bits >>> 14) & 0x3fff) + 1 };
      }
      if (type === 'VP8 ' && length >= 10) {
        const frame = await read(offset + 8, 10);
        if (text(frame, 3, 3) !== '\x9d\x01\x2a') throw invalid();
        return { width: frame.getUint16(6, true) & 0x3fff, height: frame.getUint16(8, true) & 0x3fff };
      }
      offset += 8 + length + (length % 2);
    }
  }
  throw invalid();
}
