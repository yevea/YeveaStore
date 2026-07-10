---
name: doc-page
description: Turn raw notes (voice-transcript dumps, bullet points, photo descriptions) about Yevea's wood/oil/olive/herb expertise into a structured, AI-citable /doc knowledge-base page — fact-dense answer block, specs table, FAQ with schema, cross-link to /cat — in the correct language silo and taxonomy path.
---

# Doc-page writing skill

Martín drops raw material — a voice-transcript dump, quick bullet notes, or a description of
photos taken while working — about a wood/oil/olive/herb topic. Turn it into one finished
`/doc` knowledge-base page, or several if the dump spans multiple categories. Optimized for
being cited/quoted by AI answer engines (GEO), not just ranked by Google.

## Before writing

- Identify which taxonomy leaf(es) the material belongs to (list below). If ambiguous, ask
  rather than guess.
- Confirm the language silo. **Never invent a translated URL segment not already listed
  below** — if a French segment beyond `planches` is needed, ask Martín instead of coining one.
- If a page already exists at that path, **Read it first**. Preserve anything already
  hand-validated (FAQ answers, specs) rather than overwriting wholesale — only add/update what
  the new material actually changes.

## Taxonomy (`yevea.com/doc/...`)

Source of truth: `CLAUDE.md` chuleta. Do not deviate without confirming with Martín.

```
es/  aceite/ olivas/ hierbas/ madera/{tablones,rodajas,troncos,tableros-mesa,estantes,
     encimeras-cocina,encimeras-bano,torneado,tablas-cocina,utensilios-cocina}
en/  oil/ olives/ herbs/ wood/{planks,slices,logs,table-tops,shelves,kitchen-worktops,
     bathroom-worktops,turning,cutting-boards,kitchen-utensils}
fr/  huile/ olives/ herbes/ bois/{planches,...}   <- segments beyond "planches" undefined, ask
```

File path convention: `doc/{lang}/{category}/{subcategory}/index.html` — each taxonomy leaf is
one `index.html`.

## Fixed page structure (always this order)

1. **H1** — topic-oriented title, not clickbait.
2. **Answer block** — 2–4 sentences immediately under the H1. Standalone-quotable, no
   marketing adjectives, directly answers the implicit question a person or AI assistant would
   ask. This is the paragraph most likely to get lifted verbatim into an AI answer — treat it
   as the most important text on the page.
3. **Specs table** — if the topic has measurable properties: species, Janka hardness, density
   (kg/m³), moisture %, oil content %, harvest season, typical dimensions, price range. Use a
   real `<table>`, not prose — structured data is what gets extracted and quoted; adjectives
   don't.
4. **Body sections** — use cases, how to choose vs. alternatives, care/maintenance (phrase as
   numbered steps so it's HowTo-eligible).
5. **FAQ** — 3–5 Q&A pairs phrased the way someone would actually ask a chatbot or voice
   assistant (e.g. "¿qué madera aguanta mejor la humedad en un baño?"), not SEO-keyword
   phrasing.
6. **Cross-link to `/cat`** — link to the relevant family/product listing. Only link to `/cat`
   URLs you can confirm are real (check `routes.json`/sitemap or ask Martín) — never invent one.
7. **Breadcrumb** trail matching the taxonomy path.

## Schema.org — write it alongside the content, not as an afterthought

- `FAQPage` JSON-LD mirroring the FAQ section **word for word** — never let the visible FAQ and
  the schema FAQ drift apart.
- `BreadcrumbList` JSON-LD mirroring the breadcrumb.
- `HowTo` JSON-LD for any care/maintenance section written as numbered steps.

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "...",
      "acceptedAnswer": { "@type": "Answer", "text": "..." }
    }
  ]
}
</script>
```

## Multilingual rules

- Spanish is the source of truth for facts (BD/content truth lives in Spanish per
  `CLAUDE.md`). Numbers, species names, and specs must stay consistent across all three
  language versions of a leaf.
- **Never machine-translate 1:1** into en/fr. Write each locale's prose as if for a native
  reader of that language — near-duplicate content across silos is a distinct-content problem,
  not a translation nicety, and flattens pages that would otherwise be independently
  citable/valuable.
- Don't publish a leaf in only one language silo without flagging that the other two are still
  missing — thin coverage in two of three locales undermines the whole silo's authority.

## After writing a page

- Flag (or do it, if asked) that the corresponding `llms.txt` entry needs adding/updating.
- Recommend running the new JSON-LD through Google's Rich Results Test before/after publishing.
- Note the leaf's new status for the "Plan contenido" tab in `SettingsYeveaStore` if that
  workflow is in use.

## What not to do

- Don't invent taxonomy leaves, translated URL segments, or `/cat` links that aren't confirmed
  to exist.
- Don't pad the answer block or specs table with persuasive/marketing language ("premium",
  "calidad excepcional") — that belongs on `/cat`, not `/doc`. The KB's value is being
  neutral and fact-dense.
- Don't skip the FAQ/schema step because the prose is "done" — the schema is what makes the
  page machine-citable, not optional polish.
