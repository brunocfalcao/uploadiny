export function clipboardFiles(data, now = new Date()) {
    let files = [...(data?.files || [])];
    if (!files.length) files = [...(data?.items || [])].filter(item => item.kind === 'file').map(item => item.getAsFile()).filter(Boolean);
    const stamp = now.toISOString().slice(0, 19).replace(/[T:]/g, '-');
    return files.map((file, index) => {
        if (file.name && !/^image\.(png|jpe?g|gif|webp)$/i.test(file.name)) return file;
        const extension = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
        return new File([file], `pasted-${stamp}${files.length > 1 ? `-${index + 1}` : ''}.${extension}`, { type: file.type });
    });
}
