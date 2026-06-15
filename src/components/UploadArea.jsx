import { useMemo } from 'react';
import { useDropzone } from 'react-dropzone';

const MAX_FILE_SIZE = 20 * 1024 * 1024;
const ACCEPTED_TYPES = {
  'image/jpeg': ['.jpg', '.jpeg'],
  'image/png': ['.png'],
  'image/webp': ['.webp'],
};

export default function UploadArea({ onFileSelected, previewUrl, error }) {
  const fileTypesLabel = useMemo(() => '.jpg, .jpeg, .png, .webp', []);

  const onDrop = (acceptedFiles) => {
    if (acceptedFiles.length > 0) {
      onFileSelected?.(acceptedFiles[0]);
    }
  };

  const { getRootProps, getInputProps, isDragActive, fileRejections } = useDropzone({
    onDrop,
    multiple: false,
    maxSize: MAX_FILE_SIZE,
    accept: ACCEPTED_TYPES,
  });

  const rejectionMessage = fileRejections?.[0]?.errors?.[0]?.message;

  return (
    <section className="upload-area">
      <div {...getRootProps({ className: `upload-dropzone ${isDragActive ? 'is-active' : ''}` })}>
        <input {...getInputProps()} />
        {previewUrl ? (
          <img src={previewUrl} alt="Panorama preview" className="upload-preview" />
        ) : (
          <>
            <p className="eyebrow">Upload panorama</p>
            <h3>Drop a room image or browse files</h3>
            <p>Accepted formats: {fileTypesLabel}</p>
            <p>Maximum size: 20 MB</p>
          </>
        )}
      </div>

      {(error || rejectionMessage) && (
        <p className="field-error">{error || rejectionMessage}</p>
      )}
    </section>
  );
}