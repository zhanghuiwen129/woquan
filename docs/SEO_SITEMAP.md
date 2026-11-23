# 我圈 SEO 功能说明

我圈内置了一套完整的搜索引擎优化（SEO）方案，覆盖站点级抓取配置、结构化数据、社交分享元信息三个层面，帮助搜索引擎更好地收录与展示站点内容。

## 功能概览

| 功能 | 作用 | 位置 |
|------|------|------|
| `sitemap.xml` | 向搜索引擎提交全站 URL 索引 | 根目录 |
| `robots.txt` | 控制爬虫抓取范围与权限 | 根目录 |
| JSON-LD 结构化数据 | 告诉搜索引擎页面内容的语义结构 | 页面 `<head>` 内联 |
| Open Graph 标签 | 优化社交平台分享卡片展示 | 页面 `<head>` 内联 |

---

## 1. sitemap.xml（站点地图）

### 说明

`sitemap.xml` 自动生成站点所有公开可访问页面的 URL 索引，供搜索引擎爬虫（Googlebot、Baiduspider 等）发现与抓取内容。

### 核心特性

- **收录范围**：首页、文章详情 `/p/{id}`、用户主页 `/u/{username}`、分类 `/c/{id}`、话题 `/topic/{name}`。
  话题同时收录后台维护的话题表和 `post_tags` 中至少 2 篇动态的标签（两者大小写不敏感去重）。
- **单文件输出**：`sitemap.xml` 一次输出全部 URL，不分片；文章最多 1000 条，用户与话题各最多 500 条。
- **更新频率**：根据内容类型设置合理的 `<lastmod>`、`<changefreq>`、`<priority>`（`<lastmod>` 只在文章页与部分话题页输出，首页 / 用户主页 / 分类页不输出）：
  - 首页：`changefreq=always`，`priority=1.0`
  - 文章详情：`changefreq=daily`，`priority=0.8`
  - 用户主页：`changefreq=weekly`，`priority=0.6`
  - 分类 / 话题：`changefreq=weekly`，`priority=0.5`
- **协议规范**：遵循 [sitemaps.org](https://www.sitemaps.org/protocol.html) 协议。

### 示例

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://example.com/</loc>
    <changefreq>always</changefreq>
    <priority>1.0</priority>
  </url>
  <url>
    <loc>https://example.com/p/101</loc>
    <lastmod>2026-08-06</lastmod>
    <changefreq>daily</changefreq>
    <priority>0.8</priority>
  </url>
</urlset>
```

### 提交方式

1. 在 `robots.txt` 中声明 `Sitemap: https://example.com/sitemap.xml`。
2. 在搜索引擎站长平台（Google Search Console、百度搜索资源平台）主动提交。

---

## 2. robots.txt（爬虫协议）

### 说明

`robots.txt` 位于站点根目录，用于声明各搜索引擎爬虫的抓取规则，控制哪些路径允许抓取、哪些路径禁止抓取。

### 核心规则

- **默认策略**：`User-agent: *` + `Allow: /`，全站放行；管理后台、API 等路径由鉴权保护，不靠 robots 拦。
- **自定义**：在后台「设置 → 站点与界面 → robots.txt 自定义规则」填写任意规则；留空恢复默认策略。
- **Sitemap 声明**：系统自动在末尾追加 `Sitemap: {站点URL}/sitemap.xml`，自定义内容里不用重复写。

### 示例

```text
User-agent: *
Allow: /
Disallow: /admin/

Sitemap: https://example.com/sitemap.xml
```

### 注意事项

- `robots.txt` 是建议性协议，恶意爬虫可能忽略。敏感接口应配合服务端鉴权保护。
- 修改路径规则后，搜索引擎重新抓取生效需要一定时间（通常 24 小时内）。

---

## 3. JSON-LD 结构化数据

### 说明

JSON-LD（JavaScript Object Notation for Linked Data）是 Google 推荐的结构化数据格式。我圈在页面 `<head>` 中内联 JSON-LD 脚本，向搜索引擎描述页面内容的语义类型，有助于获得富媒体搜索结果（Rich Results）。

### 支持的结构化数据类型

- **WebSite**：站点级信息，用于站点搜索框（Sitelinks Search Box）。
- **BlogPosting**：动态/文章详情页，展示作者、发布时间、配图等富媒体摘要（schema.org 中属 Article 子类）。
- **Person**：仅作为文章作者内嵌于 BlogPosting，用户主页无独立 JSON-LD。
- **BreadcrumbList**：面包屑导航，展示页面层级路径。
- **Organization**：仅作为文章 publisher 内嵌。

### 示例（动态详情页 BlogPosting）

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BlogPosting",
  "headline": "周末游记",
  "image": ["https://example.com/uploads/posts/101-1.jpg"],
  "datePublished": "2026-08-06T09:30:00+08:00",
  "dateModified": "2026-08-06T09:30:00+08:00",
  "author": {
    "@type": "Person",
    "name": "admin"
  },
  "publisher": {
    "@type": "Organization",
    "name": "我圈",
    "logo": {
      "@type": "ImageObject",
      "url": "https://example.com/uploads/logo.png"
    }
  },
  "mainEntityOfPage": {
    "@type": "WebPage",
    "@id": "https://example.com/p/101"
  }
}
</script>
```

### 验证方式

- [Google Rich Results Test](https://search.google.com/test/rich-results)
- [Schema.org Validator](https://validator.schema.org/)

---

## 4. Open Graph 与社交分享标签

### 说明

Open Graph（OG）协议由 Facebook 提出，现已被微信、微博、Twitter、Telegram 等主流社交平台广泛支持。我圈在页面 `<head>` 中输出 OG 标签与 Twitter Card 标签，确保链接被分享时展示标题、描述与预览图。

### 输出的 Meta 标签

| 标签 | 说明 |
|------|------|
| `og:site_name` | 站点名称 |
| `og:title` | 页面标题 |
| `og:description` | 页面描述（动态正文摘要） |
| `og:url` | 页面规范 URL |
| `og:image` | 预览图（动态首图或站点默认图） |
| `og:type` | 内容类型（article / website） |
| `twitter:card` | Twitter 卡片类型（summary_large_image） |
| `twitter:title` | Twitter 标题 |
| `twitter:description` | Twitter 描述 |
| `twitter:image` | Twitter 预览图 |

### 示例

```html
<meta property="og:site_name" content="我圈">
<meta property="og:title" content="周末游记">
<meta property="og:description" content="周末去了山里徒步，风景很美...">
<meta property="og:url" content="https://example.com/p/101">
<meta property="og:image" content="https://example.com/uploads/posts/101-1.jpg">
<meta property="og:type" content="article">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="周末游记">
<meta name="twitter:description" content="周末去了山里徒步，风景很美...">
<meta name="twitter:image" content="https://example.com/uploads/posts/101-1.jpg">
```

### 验证方式

- [Facebook Sharing Debugger](https://developers.facebook.com/tools/debug/)
- [Twitter Card Validator](https://cards-dev.twitter.com/validator)

---

## 配置说明

SEO 相关配置项位于站点后台 **设置 → 站点与界面**，可自定义以下内容：

- **站点 URL**：用于 canonical、`og:url` 与 sitemap 的绝对地址；留空则按访问域名自动推导
- **站点关键词 / 站点描述**：写入页面 meta
- **robots.txt 自定义规则**：纯文本，留空使用默认全站放行；Sitemap 行由系统自动追加
- **站点名称 / 站点简介**：用于 `og:site_name` 与标题后缀

meta 标签与 JSON-LD 由框架按页面类型自动生成，没有独立的结构化数据开关。canonical 标签也由框架自动输出；未知路径（软 404）与带关键词的搜索结果页会自动输出 `noindex,follow`，无需单独配置。

## 搜索引擎收录建议

1. **提交站点地图**：在 Google Search Console 与百度搜索资源平台提交 `sitemap.xml`。
2. **保持内容更新**：定期发布原创内容，`lastmod` 会自动更新。
3. **检查抓取状态**：通过站长平台查看索引覆盖率与抓取错误。
4. **移动端适配**：我圈默认响应式设计，符合 Google 移动优先索引要求。
5. **页面性能**：开启缓存与 Gzip 压缩，提升页面加载速度（搜索引擎排名因素之一）。
