export async function prepareHeroPhoto(file) {
    if (!file || file.size <= 2 * 1024 * 1024) return file;
    const image = await createImageBitmap(file);
    try {
        const scale = Math.min(1, 1920 / Math.max(image.width, image.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(image.width * scale));
        canvas.height = Math.max(1, Math.round(image.height * scale));
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Foto kon niet worden verkleind.');
        context.fillStyle = '#FFFFFF';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        if (!blob) throw new Error('Foto kon niet worden verkleind.');
        return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: file.lastModified });
    } finally {
        image.close();
    }
}
