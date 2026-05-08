# NinjaWeb Content Pilot

Version 0.2.1 changes the engine to a stable article pipeline:

1. AI generates article body HTML only.
2. Plugin saves the generated article even if quality checks fail.
3. Plugin assembles slug, excerpt, category, tags, Yoast SEO title, meta description, and image brief locally.
4. Draft creation is a separate step from article generation.
5. Image generation is off. The plugin only stores a manual image brief.
6. Knowledge Base injection is supported with baseline NinjaWeb operational and technical knowledge.

Recommended testing:

- Generation mode: Manual only
- Use Knowledge Base: Yes
- Knowledge Strength: Balanced
- Minimum word count: 900
- SEO meta mode: Local

Workflow:

Ideas -> Queue -> Generate Article -> Review Saved Article -> Create Draft -> Edit/Approve in WordPress
