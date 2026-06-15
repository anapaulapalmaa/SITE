export default function PromptForm({ prompt, onPromptChange, onSubmit, disabled }) {
  return (
    <form className="prompt-form" onSubmit={onSubmit}>
      <label htmlFor="arch3-prompt" className="prompt-label">
        Prompt
      </label>
      <textarea
        id="arch3-prompt"
        value={prompt}
        onChange={(event) => onPromptChange?.(event.target.value)}
        placeholder='Transform into a warm modern architectural living room with natural wood, ambient lighting and premium furniture.'
        rows={6}
      />

      <button type="submit" className="generate-button" disabled={disabled}>
        Generate
      </button>
    </form>
  );
}