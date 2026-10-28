import { readPhotoDimensions } from './image-dimensions.web';
import type { ImagePickerAsset, ImagePickerOptions, ImagePickerResult } from 'expo-image-picker';

const ownedUrls = new Set<string>();
/** Read only image headers to validate resolution without eager full decodes. */
export function launchImageLibraryAsync(options: ImagePickerOptions): Promise<ImagePickerResult> {
  return new Promise((resolve, reject) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/jpeg,image/png,image/webp';
    input.multiple = !!options.allowsMultipleSelection;
    input.style.display = 'none';
    const finish = async () => {
      const assets: ImagePickerAsset[] = [];
      try {
        for (const file of Array.from(input.files ?? [])) {
          const dimensions = await readPhotoDimensions(file);
          const uri = URL.createObjectURL(file);
          ownedUrls.add(uri);
          assets.push({ uri, file, fileName: file.name, fileSize: file.size, mimeType: file.type,
            type: 'image', ...dimensions });
        }
        resolve(assets.length ? { canceled: false, assets } : { canceled: true, assets: null });
      } catch (error) { releasePickedImages(assets); reject(error); }
      finally { input.remove(); }
    };
    input.addEventListener('change', finish, { once: true });
    input.addEventListener('cancel', () => { input.remove(); resolve({ canceled: true, assets: null }); }, { once: true });
    document.body.appendChild(input);
    input.click();
  });
}

export function releasePickedImages(assets: readonly { uri: string }[]) {
  for (const { uri } of assets) if (ownedUrls.delete(uri)) URL.revokeObjectURL(uri);
}
