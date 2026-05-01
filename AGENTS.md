# AGENTS.md — Conventions transverses pour les assistants IA

> Le contexte projet complet (stack, architecture, conventions, pièges,
> workflow de release…) vit dans [`CLAUDE.md`](CLAUDE.md). Ce fichier
> ne contient que des **règles comportementales courtes** que n'importe
> quel agent (Claude Code, Cursor, Aider, Copilot Workspace…) doit
> appliquer en plus de `CLAUDE.md`.

---

## Règles de workflow

### Quand le user suggère une nouvelle idée

Quand le user propose une **nouvelle feature, amélioration, ou bug
non-trivial qui ne sera pas implémenté dans le tour de conversation
courant** :

1. **Ouvrir un ticket GitHub** via `gh issue create`.
   - Titre en style conventional-commit (ex. `feat(lastfm): support
     du fuzzy match Levenshtein`, `fix(stats): wrapped non-numeric
     warning`, `perf(import): batched flush sur les gros imports`).
   - Body : explique le **pourquoi** (le besoin, le contexte) plus
     qu'un cahier des charges figé. Si applicable, recopie l'extrait
     de conversation qui a donné l'idée.
2. **Catégoriser** avec :
   - un label de domaine : `area:lastfm`, `area:playlists`,
     `area:stats`, `area:integrations`, `area:cron`, `area:export` ;
   - un label d'effort : `size:S` (< 1 jour), `size:M` (1-2 jours),
     `size:L` (3+ jours) ;
   - éventuellement `type:bug` / `type:enhancement` /
     `type:tech-debt`.
3. **Mettre à jour [`ROADMAP.md`](ROADMAP.md)** : ajouter la ligne
   dans la table de la section `## Icebox` correspondante, avec la
   référence `[#NN]` en bas du fichier (format déjà en usage).
4. **Confirmer le titre + la catégorie avec le user** avant d'ouvrir
   le ticket — il pourra le retoucher avant que ce soit posté
   publiquement sur le repo.

Cette règle ne s'applique **qu'aux items prospectifs**. Un bug que
le user veut corrigé **maintenant** continue d'être traité inline
dans la session, comme aujourd'hui (au choix : commit + push direct,
ou via un ticket si on veut tracer la décision).
