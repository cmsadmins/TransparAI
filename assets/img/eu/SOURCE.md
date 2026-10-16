# Official EU icons for labelling AI-generated content

Source: European Commission, Directorate-General for Communications Networks, Content and
Technology, "EU icons for labelling AI-generated content", published with the Code of Practice on
Transparency of AI-Generated Content on 10 June 2026.

- Page: https://digital-strategy.ec.europa.eu/en/policies/eu-icons-labelling-ai-generated-content
- SVG archive: https://ec.europa.eu/newsroom/dae/redirection/document/129546
  (SHA-256 of the ZIP: f76707b69b546677b173975c018528e5e549e12afb05550f32c2f46500ffbefd, fetched 2026-10-02)

The files in this folder are the Commission's SVGs, unchanged; only the file names were lowercased
and the spaces removed.

| File | Original name | Meaning |
|---|---|---|
| ai-basic-black.svg, ai-basic-white.svg | LABEL_AI_black.svg, LABEL_AI_white.svg | Basic icon: AI was involved; meant to be accompanied by text |
| ai-generated-black.svg, ai-generated-white.svg | LABEL_AI GENERATED_black.svg, LABEL_AI GENERATED_white.svg | Fully AI-generated content |
| ai-modified-black.svg, ai-modified-white.svg | LABEL_AI MODIFIED_black.svg, LABEL_AI MODIFIED_white.svg | Partially AI-modified content |

The archive also ships 50 percent transparent variants; the plugin reproduces that look with its
own opacity setting instead of bundling them.

Terms, as stated on the Commission's page: the icons may be used by anyone, free of charge and
without attribution. Using them does not make a site a signatory of the Code of Practice and must
not be presented as such, and neither the icons nor this plugin certify compliance with the AI Act.

## PNG copies for preview images

`ai-*-black.png` and `ai-*-white.png` are the same six icons rasterised once at 379 x 120 pixels
with a transparent background (headless Chrome, 2026-10-16). PHP's GD library cannot read SVG, and
the link preview image (Settings, Badge, "Label the link preview image") is drawn with GD on the
server. Nothing else about the icons was changed.
