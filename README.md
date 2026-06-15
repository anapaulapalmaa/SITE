# Arch3 — Premium Landing Page

Um site moderno, elegante e sofisticado para a startup conceitual **Arch3**.

## 📋 Sobre

Este é um landing page de alta qualidade desenvolvido com:
- **HTML5 semântico**
- **Tailwind CSS** (via CDN)
- **Animações suaves** (AOS - Animate On Scroll)
- **Tipografia premium** (Playfair Display + Inter)
- **Design responsivo** (desktop, tablet, mobile)
- **Interatividade** (slider before/after, formulário funcional)

## 🎨 Características

✅ Navbar sticky/fixa com design cápsula  
✅ Hero section com imagem principal e floating cards  
✅ Comparador before/after com slider funcional  
✅ Mockup de celular  
✅ Seção de features com grid 4x2  
✅ Pricing com planos destacados  
✅ Roadmap visual  
✅ Formulário de waitlist funcional  
✅ Footer minimalista  
✅ Animações fade-in ao scroll  
✅ Paleta roxo/azul com gradientes  

## 🚀 Como usar

### Opção 1: Abrir no navegador
```bash
open /Users/Ana/SITE/index.html
```

### Opção 2: Usar um servidor local (Python)
```bash
cd /Users/Ana/SITE
python3 -m http.server 8000
```
Depois acesse: `http://localhost:8000`

### Opção 3: Live Server no VS Code
1. Abra a pasta `/Users/Ana/SITE` no VS Code
2. Clique em "Go Live" (canto inferior direito)
3. O site abrirá automaticamente

## 🖼️ Substituir imagens e logo

### Logo
Os arquivos de logo atuais são SVGs:
- `logo-icon.svg` — usado na navbar e footer

Para usar suas próprias imagens PNG:
1. Coloque os arquivos na pasta `/Users/Ana/SITE/`
2. Abra `index.html` no editor
3. Procure por `logo-icon.svg` e substitua por `logo-icon.png` (ou outro nome)

### Imagens da Hero e seções
O site atual usa imagens do Unsplash (online). Para usar imagens locais:
1. Coloque as imagens na pasta `/Users/Ana/SITE/`
2. Procure pelas URLs de imagens no HTML:
   - `https://images.unsplash.com/photo-1556909114-f6e7ad7d3136...` (imagem principal)
   - `https://images.unsplash.com/photo-1516455207990-7a41e1d4ffd5...` (before)
   - `https://images.unsplash.com/photo-1516455207990...` (after)
3. Substitua pelas suas imagens locais: `src="seu-arquivo.jpg"`

## 🎯 Estrutura do site

```
Navbar (sticky) — Links + botão "Join Waitlist"
↓
Hero — Título grande, imagem, floating cards
↓
Problem — Texto + before/after slider
↓
Solution — Phone mockup + checklist
↓
How It Works — 4 cards com steps
↓
Key Features — Grid 4x2 de features
↓
Pricing — 5 planos com destaque
↓
Why Arch3 — Seção editorial
↓
About — Personal project + chips
↓
Roadmap — 4 phases
↓
Waitlist — Formulário
↓
Footer — Links minimalistas
```

## 🎨 Customização

### Cores
As cores principais estão definidas no `<style>`:
```css
--primary-purple: #8B5CF6
--primary-blue: #3B82F6
--dark-text: #121426
--secondary-gray: #6B7280
--light-bg: #F8F9FC
```

Para mudar: edite os valores HEX no CSS

### Tipografia
- Títulos: `Playfair Display` (serif, elegante)
- Texto: `Inter` (sans-serif, moderno)

### Animações
As animações são controladas por AOS (Animate On Scroll):
- `data-aos="fade-right"` — entra da esquerda
- `data-aos="fade-left"` — entra da direita
- `data-aos="fade-up"` — entra de baixo

## 📱 Responsividade

O site é totalmente responsivo:
- **Desktop**: layout completo com 2 colunas
- **Tablet**: layout adaptado
- **Mobile**: colunas empilhadas, navbar compacta

Testado em todos os tamanhos de tela via Tailwind CSS.

## ✨ Funcionalidades

### Slider Before/After
- Arraste horizontalmente para comparar
- Funciona com mouse e touch
- Sempre centralizado

### Formulário
- 4 campos: Name, Country, Email, Space type
- Validação HTML5 nativa
- Mensagem de sucesso ao enviar

### Links de navegação
- Navbar links scrollam suavemente
- Botão "Join Waitlist" leva ao formulário
- Todos os links estão funcionando

## 🔧 Suporte

Se tiver dúvidas:
1. Certifique-se de que o arquivo está em `/Users/Ana/SITE/index.html`
2. Abra no navegador (Firefox, Chrome, Safari)
3. Verifique se todas as fontes carregaram (verificar console)
4. Se as imagens não aparecerem, revise os caminhos dos arquivos

## 🧪 Playground MVP (/generate360)

Área de testes **isolada** para o MVP: upload de panorama → prompt → geração via OpenAI → visualizador 360°.
A homepage (`index.html`) permanece intacta e não referencia esta página.

### Rodar o backend
```bash
# 1. Configurar a chave da OpenAI
cp .env.example .env   # depois edite .env e preencha OPENAI_API_KEY

# 2. Subir o servidor Express (serve site + API)
npm start
```
Depois acesse:
- Site: `http://localhost:3000/`
- **Try It (produto)**: `http://localhost:3000/try-it` — página principal do MVP, no estilo do site
- Playground (sandbox de dev): `http://localhost:3000/generate360` (ou `/playground`)

### Botões do site
Todos os antigos CTAs "Join Waitlist" agora exibem **TRY IT!** e levam para `/try-it`.

### Estrutura
```
backend/
├── server.js                  # Express: serve HTML estático + monta /api
├── routes/generate.js         # POST /api/generate (playground)
├── routes/generateRedesign.js # POST /api/generate-redesign (Try It — prompt base arquitetônico)
└── uploads/                   # arquivos temporários (criados em runtime)

try-it.html            # página "Try It" — produto MVP no estilo do site

src/
├── components/        # PanoramaViewer, UploadArea, PromptForm (React, referência)
├── pages/Generate360.jsx
├── services/openaiService.js          # usado pelo playground (/api/generate)
└── services/imageGeneration/          # abstração de provider (usada pelo Try It)
    ├── index.js                       # generatePanoramicRedesign() — orquestra
    ├── prompt.js                      # regras arquitetônicas + diretivas panorâmicas
    ├── providers/openaiProvider.js    # implementação OpenAI (trocável → IMAGE_PROVIDER)
    └── expansion/                     # expande p/ 2:1 (nunca serve quadrado)
        ├── index.js                   # registry de estratégias (PANORAMA_EXPANSION)
        └── ambientFill.js             # MVP: sharp, preenchimento ambiente desfocado

generate360.html       # implementação ativa do playground (vanilla + import maps)
```

> Os endpoints recebem `image` (arquivo) + `prompt` (texto). O `/api/generate-redesign`
> retorna `{ imageUrl, width, height, expanded, provider }` — a imagem é sempre um
> **panorama ~2:1** (ex.: 2048x1024), nunca quadrada, pronta para o viewer 360°.
> A chave da OpenAI vive apenas no backend, nunca no frontend.

### Trocar de provider de imagem (futuro)
Toda a geração do Try It passa por `src/services/imageGeneration/`. Para usar
Stability AI, Flux, Replicate ou um modelo panorâmico: crie
`providers/<nome>Provider.js` com `generate({ imagePath, prompt }) → { buffer, mimeType }`,
registre em `index.js` e defina `IMAGE_PROVIDER=<nome>` no `.env`. A interface do
usuário não muda.

### Trocar a expansão panorâmica (futuro)
Hoje o MVP usa **preenchimento ambiente** (laterais desfocadas, sem IA). Para
substituir por outpainting real ou um modelo panorâmico: crie
`expansion/<nome>.js` com `expand(buffer, options) → { buffer, width, height, expanded }`,
registre em `expansion/index.js` e defina `PANORAMA_EXPANSION=<nome>` no `.env`.
Estratégias planejadas: `outpaint-openai`, `stability`, `replicate`, `panorama-native`.

## 📄 Licença

Projeto pessoal — Arch3 Concept 2026

---

**Última atualização**: Maio 17, 2026
