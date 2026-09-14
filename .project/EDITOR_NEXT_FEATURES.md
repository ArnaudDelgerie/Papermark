# Editor — Next Features

## AI (Crepe Feature.AI)

### Architecture

```
Milkdown AI ──POST instruction──▶ Symfony endpoint
                                      │
                                      ▼
                                 Messenger bus
                                      │
                                      ▼
                                   Worker
                                      │ stream tokens
                                      ▼
                                 Mercure hub (SSE)
                                      │
                                      ▼
Milkdown AI ◀──AsyncIterable──────────┘
```

- L'utilisateur selectionne du texte dans l'editeur, un tooltip IA apparait avec un champ de prompt et des suggestions
- Le JS poste l'instruction (selection + prompt) sur un endpoint Symfony
- L'endpoint publie un message sur le bus Messenger
- Un worker consomme le message, appelle le provider LLM via `symfony/ai-bundle`
- Le worker streame les tokens sur un topic Mercure dedie
- Le JS souscrit au topic, yield les chunks dans l'`AsyncIterable` de Crepe
- Le diff review de Crepe (accept/reject) s'affiche a la fin du stream
- L'abort de Crepe ferme l'EventSource

### Implementation (v1)

- [x] `composer require symfony/ai-bundle symfony/mercure-bundle` + bridges OpenAI, Anthropic, Mistral + `symfony/ai-agent`
- [x] `AiPlatformFactory` — build la Platform au runtime via `Factory::createPlatform()` selon le provider choisi par l'utilisateur
- [x] `ApiKeyResolver` — récupère la clé depuis le keyring TFSApp (`SecretStoreInterface::get()`) avec fallback `.env.local` (`OPENAI_API_KEY` / `ANTHROPIC_API_KEY` / `MISTRAL_API_KEY`)
- [x] `SystemPromptInputProcessor` — prompt system reprenant le `DEFAULT_SYSTEM_PROMPT` de Crepe (markdown only, pas de code fences, pas de préambule)
- [x] `AiInstructionMessage` (Messenger) + `AiInstructionHandler` — appelle `Agent::call($messages, ['stream' => true])`, itère les `Progress` updates de stage `delta`, publie chaque `TextDelta` sur Mercure via `HubInterface::publish()`
- [x] `AiController::instruct` (`POST /ai/instruct`) — CSRF stateless token `ai`, génère un topic UUID, mint un JWT subscriber Mercure via `Authorization::setCookie()`, dispatch le message sur le bus
- [x] `AiController::config` (`GET /ai/config`) — expose `enabled` (TFS_ASYNC_WORKER) + `providers` (has_key par provider)
- [x] JS `editor_controller.js` — active `Crepe.Feature.AI` avec un `AIProvider` custom qui `fetch()` l'endpoint, ouvre un `EventSource` sur Mercure (`withCredentials: true`), yield les chunks JSON `{type: "chunk", content: "..."}`, gère `done` / `error`, respecte `signal.aborted`
- [x] Activation conditionnelle : l'IA ne s'active que si `ai_config.enabled && au moins un provider a une clé`
- [x] Bouton "Ask AI" dans la TopBar (groupe "More") via `buildTopBar` callback — déclenche `aiInstructionTooltipAPI.show(from, to)`. Le Toolbar flottant de Crepe reste désactivé (conflit de rendu Vue avec les slices AI).
- [x] Tooltip IA relocalisé vers `document.body` (voit `#relocateAiTooltip`) — Floating UI `shift()` utilise le viewport comme boundary au lieu du conteneur `.milkdown` en `overflow: hidden`. Classe `milkdown` ajoutée pour préserver les styles Crepe.
- [x] Clamp de la position `top` via MutationObserver — corrige le cas où `flip()` bascule en `placement: "top"` sur une sélection couvrant tout le document et l'infobulle sort par le haut.
- [x] `tfsapp.config.json` — `actions.secrets.bridge: true` + `keys: ["openai", "anthropic", "mistral", "ai_provider"]`
- [x] CSRF stateless token `ai` ajouté à `config/packages/csrf.yaml`
- [x] Routing Messenger : `App\Ai\AiInstructionMessage: async` dans `config/packages/messenger.yaml`
- [x] Tests : `AiControllerTest` (CSRF invalid, missing instruction, invalid provider, config endpoint) + `HomeControllerTest` (AI CSRF token + config exposés au front) + `SettingsControllerTest` (page rend, CSRF, invalid provider)
- [x] UI de settings — page `GET /settings` + `POST /settings/ai` qui sauvegarde le provider choisi et les clés via `SecretStoreInterface::set()`. Lien depuis l'éditeur (file bar). Si le bridge n'est pas disponible (dev sans hub), affiche un message avec les noms de variables `.env.local`.
- [ ] Gestion du désactivé : lire `TFS_ASYNC_WORKER` cote JS pour cacher le bouton IA — actuellement géré côté PHP via `ai_config.enabled`
- [ ] Sélection du modèle — actuellement hardcoded dans `AiPlatformFactory::DEFAULT_MODELS` (openai: gpt-4o-mini, anthropic: claude-sonnet-4-0, mistral: mistral-large-latest). Le JS n'envoie pas de `model`, le controller utilise le défaut. TODO : permettre à l'utilisateur de choisir le modèle. Probablement en BDD (pas keyring) — à traiter dans une session dédiée.

### Provider LLM (symfony/ai-bundle)

- `symfony/ai-bundle` v0.13.0 + `symfony/ai-agent` + bridges `symfony/ai-open-ai-platform`, `symfony/ai-anthropic-platform`, `symfony/ai-mistral-platform`
- La Platform est construite au runtime par `AiPlatformFactory::createPlatform()` — pas de YAML statique, car le provider et la clé sont choisis par l'utilisateur
- `Agent` construit avec `SystemPromptInputProcessor` (prompt system en dur dans le handler, reprenant le `DEFAULT_SYSTEM_PROMPT` de Crepe)
- Streaming : `Agent::call($messages, ['stream' => true])` → `Execution` itérable → `Progress` updates de stage `delta` → `TextDelta::getText()`

### Cles API (TFSApp keyring)

- Declaration dans le manifest : `actions.secrets.bridge: true`, `keys: ["openai", "anthropic", "mistral", "ai_provider"]`
- `ApiKeyResolver` récupère la clé via `SecretStoreInterface::get()` (bridge TFSApp), fallback `.env.local` en dev sans hub
- `ApiKeyResolver::resolveProvider()` — lit le provider choisi depuis le keyring (`ai_provider`), ou auto-detecte le premier provider avec une clé
- Page de settings (`SettingsController`) — sélection du provider (radio) + saisie des clés (password), sauvegarde via `SecretStoreInterface::set()`
- `ai_config.selected_provider` exposé au front via le composant Editor, le JS utilise ce provider pour l'`AIProvider`

### Worker et conditions d'execution

- `workers: [{transports: ["async"]}]` déclaré dans `tfsapp.config.json`
- `MESSENGER_TRANSPORT_DSN` = `doctrine://default` sous le hub, `sync://` en dev sans hub
- `TFS_ASYNC_WORKER` = `"1"` sous le hub — `StationContextInterface::isAsyncWorker()` exposé au front via `ai_config.enabled`
- En dev sans hub : le handler tourne en `sync://` (inline) — bloque le process le temps du stream mais fonctionne pour tester
- Routing : `App\Ai\AiInstructionMessage: async` dans `config/packages/messenger.yaml`

### UI de configuration

- [x] Page de settings (`/settings`) pour choisir le provider (radio) et saisir les clés (champs password)
- [x] Sauvegarde via `SecretStoreInterface::set()` (bridge transport) — la clé transite par PHP
- [x] Le provider choisi est stocké comme secret `ai_provider` dans le keyring
- [ ] IPC keyring (`secrets.ipc: true`) pour saisir la clé directement depuis le webview sans transiter par PHP — pas encore implémenté

## Twig Component / partial

- [x] Isoler l'éditeur dans un Twig Component (`symfony/ux-twig-component`) au lieu d'un partial inline dans `home/index`
- Composant `App\Twig\Components\Editor` (`#[AsTwigComponent('editor')]`) : props publiques `height` / `readonly`, getters `#[ExposeInTemplate]` pour l'objet i18n (construit côté PHP depuis le Translator), les tokens CSRF upload/file, et la hauteur CSS (suffixe `px` si numérique)
- Template `templates/components/editor.html.twig` : markup de la file bar (boutons Open/Save/Save as/Print/A4/Readonly), `data-editor-i18n-value` rempli par le composant
- `home/index.html.twig` rend l'éditeur via `{{ component('editor', { height: '100%' }) }}`
- Tests : `HomeControllerTest` (rendu HTTP, i18n, CSRF, boutons) + `EditorComponentTest` (height numérique/unitaire, readonly)
- Base pour le futur multi-éditeur / workspace (système d'onglets à la VS Code)

## A4 page preview

- [x] Afficher l'éditeur au format A4 (largeur 21cm centrée sur desk sombre)
- Guide visuel pour anticiper les coupures de tableau, images, etc.
- Implémenté : page centrée 21cm sur desk sombre, typo 11pt partagée écran/print via `document.css`, toggle bouton A4/Full width
- Guides de page retirés : pas de solution fiable en CSS/JS pour simuler les page breaks (background-attachment: local non supporté en WebKitGTK, overlay JS imprécis). Le mode A4 ne sert qu'à la largeur d'affichage.
- Print : sérialisation du document ProseMirror via `DOMSerializer.fromSchema()` dans un `.print-copy` dédié (pas de stylage de l'éditeur en print), `@page` A4 avec marges GTK natives (6.35mm) compensées en padding inline, `orphans`/`widows` + `break-inside: avoid` sur blocs, `break-after: avoid` sur titres
- CSS restructuré : `app.css` (imports only) → `document.css` (typo partagée), `editor.css` (éditeur + A4), `print.css` (print copy), thème Crepe en `layer(crepe)` pour éviter les conflits de spécificité

## Flash messages

- [x] Retour utilisateur après save (ex: "File saved")
- [x] Système de toast Stimulus (`toast_controller.js`) : écoute `toast:show` CustomEvents sur `window`, affiche et auto-dismiss (4s), bouton close
- [x] Container toast dans `base.html.twig` (`data-controller="toast"`), CSS minimal dans `toast.css` (tokens existants, à repasser quand tous les éléments UI existeront)
- [x] Traductions dans le domain `components` (`components.editor.toast.*` pour les success, `components.editor.error.*` pour les erreurs)
- [x] Endpoint-driven errors : FileController retourne le message traduit dans `{'error': '...'}` via TranslatorInterface, le JS affiche `data.error` tel quel avec fallback générique
- [x] Success messages restent côté JS (feedback UI pur : "File saved", "File saved as {name}", "File opened")
- [x] Tests : HomeControllerTest (container toast + i18n), FileControllerTest (erreurs traduites : not_found, unsupported_file_type, no_path, invalid_csrf)
- [x] À combiner avec les erreurs (upload)

## Multi-éditeur / workspace

- Système d'onglets à la VS Code
- Dépend de Twig Component (chaque onglet = instance du composant éditeur)
- Un fichier à la fois pour commencer, puis multi-fichiers
- Gestion de l'état : `#currentPath` par onglet, dirty flag, etc.

## Autres features candidates (a discuter)

- [x] Slash commands (`@milkdown/plugin-slash`) — menu `/` pour insertion rapide de blocs (deja integre via BlockEdit, libelles traduits)
- [x] Trailing paragraph (`@milkdown/plugin-trailing`) — paragraphe vide final
- [x] Traduction des libelles LinkTooltip via `|trans`
- [x] Traduction des libelles Slash menu via `|trans`
- [x] Export programmatique du markdown (`crepe.getMarkdown()`)
- [x] Export PDF via window.print() + CSS print (theme blanc, :has() pour cacher l'UI, break-inside: avoid sur tableaux/images/code blocks)
- [3] Print: nom de fichier PDF pré-rempli depuis le fichier ouvert/sauvé — `document.title` ne marche pas avec WebKitGTK (utilise "sortie.pdf" par défaut). Alternatives : 1) le hub pourrait exposer un print config IPC (set default filename), 2) générer le PDF côté app (jsPDF/html2pdf) et utiliser `save_path` pour le chemin, 3) accepter le défaut WebKitGTK
- [x] Theme CodeMirror personnalise (blanc forcé en print)
- [x] Open/Save fichier (pick_path + save_path via TFSApp hub, FileController backend)
- [x] Save as — CTA dédié, dialog save_path pré-rempli avec le nom courant ; Save désactivé pour les nouveaux fichiers (#currentPath null)
- [x] TopBar scroll-hide (overlay absolute, translateY -100%, overflow hidden sur .milkdown)
- [x] Toggle readonly (bouton Read only / Edit, contenteditable off, TopBar/handles cachés)
- [x] Sanitization `<br />` backend (sanitizeMarkdown dans FileController)
- [ ] Emoji picker (non-Milkdown, bibliotheque externe necessaire)
- [x] Upload image sur disque avec suppression auto (#diffImageUrls, #deleteImage)
- [ ] Nettoyage du dossier d'upload quand le dernier fichier d'un hash est supprimé (cosmétique)
