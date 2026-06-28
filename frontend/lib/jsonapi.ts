// ヘッドレス Drupal (JSON:API) からデータを取得・正規化するデータ層。
// サーバーサイドでのみ呼ばれる（page.tsx のサーバーコンポーネント）。CORS 不要。

const BASE = process.env.DRUPAL_JSONAPI_BASE ?? 'http://localhost:8080';

export type ResourceType = 'book' | 'article';

export type Term = { tid: number; name: string };

export type Resource = {
  id: string;
  type: ResourceType;
  title: string;
  subtitle: string;
  url: string | null;
  description: string;
  techStack: string[];
  audience: string[];
  avgReadability: number | null;
  avgPracticality: number | null;
};

export type ResourceQuery = {
  type?: string;
  tech?: string;
  aud?: string;
  q?: string;
};

const PLATFORM_LABEL: Record<string, string> = {
  qiita: 'Qiita',
  zenn: 'Zenn',
  other: 'その他',
};

const JSONAPI_HEADERS = { Accept: 'application/vnd.api+json' };

function stripHtml(html: string): string {
  return html.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
}

/** 1 種別 (book / article) を JSON:API filter 付きで取得し、正規化して返す。 */
async function fetchType(type: ResourceType, opts: ResourceQuery): Promise<Resource[]> {
  const params: string[] = [
    'include=field_tech_stack,field_target_audience',
    'sort=-created',
    'page[limit]=50',
  ];
  if (opts.tech) {
    params.push(`filter[field_tech_stack.drupal_internal__tid]=${encodeURIComponent(opts.tech)}`);
  }
  if (opts.aud) {
    params.push(`filter[field_target_audience.drupal_internal__tid]=${encodeURIComponent(opts.aud)}`);
  }
  if (opts.q) {
    params.push('filter[t][condition][path]=title');
    params.push('filter[t][condition][operator]=CONTAINS');
    params.push(`filter[t][condition][value]=${encodeURIComponent(opts.q)}`);
  }

  const url = `${BASE}/jsonapi/node/${type}?${params.join('&')}`;
  const res = await fetch(url, { headers: JSONAPI_HEADERS, next: { revalidate: 30 } });
  if (!res.ok) {
    throw new Error(`JSON:API ${type} returned ${res.status}`);
  }
  const json = await res.json();
  return normalize(type, json);
}

/** JSON:API のレスポンス（data + included）を素直な Resource[] に変換。 */
function normalize(type: ResourceType, json: any): Resource[] {
  // included の taxonomy_term を id(uuid) → name で引けるように。
  const names = new Map<string, string>();
  for (const inc of json.included ?? []) {
    if (inc?.attributes?.name) {
      names.set(inc.id, inc.attributes.name);
    }
  }

  return (json.data ?? []).map((n: any): Resource => {
    const a = n.attributes ?? {};
    const rels = n.relationships ?? {};
    const refNames = (rel: string): string[] =>
      (rels[rel]?.data ?? [])
        .map((d: any) => names.get(d.id))
        .filter((x: unknown): x is string => Boolean(x));

    const subtitle =
      type === 'book'
        ? [a.field_author, a.field_publisher].filter(Boolean).join(' / ')
        : [PLATFORM_LABEL[a.field_platform] ?? a.field_platform, a.field_author_handle]
            .filter(Boolean)
            .join(' / ');

    return {
      id: a.drupal_internal__nid != null ? String(a.drupal_internal__nid) : n.id,
      type,
      title: a.title ?? '(無題)',
      subtitle,
      url: a.field_url?.uri ?? null,
      description: a.field_description?.value ? stripHtml(a.field_description.value) : '',
      techStack: refNames('field_tech_stack'),
      audience: refNames('field_target_audience'),
      avgReadability: a.average_readability ?? null,
      avgPracticality: a.average_practicality ?? null,
    };
  });
}

/**
 * 検索一覧用：type 指定が無ければ book / article を並列取得して結合。
 * 片方の取得が失敗しても落とさず（空配列フォールバック）一覧を返す。
 */
export async function fetchResources(opts: ResourceQuery): Promise<Resource[]> {
  const types: ResourceType[] =
    opts.type === 'book' || opts.type === 'article' ? [opts.type] : ['book', 'article'];

  const lists = await Promise.all(
    types.map((t) =>
      fetchType(t, opts).catch((e) => {
        console.error('[fetchResources]', e);
        return [] as Resource[];
      }),
    ),
  );
  return lists.flat();
}

/** フィルタ UI 用の taxonomy term 一覧。失敗時は空配列。 */
export async function fetchTerms(vocab: 'tech_stack' | 'target_audience'): Promise<Term[]> {
  try {
    const url = `${BASE}/jsonapi/taxonomy_term/${vocab}?sort=name&page[limit]=100`;
    const res = await fetch(url, { headers: JSONAPI_HEADERS, next: { revalidate: 300 } });
    if (!res.ok) return [];
    const json = await res.json();
    return (json.data ?? []).map((t: any): Term => ({
      tid: t.attributes.drupal_internal__tid,
      name: t.attributes.name,
    }));
  } catch (e) {
    console.error('[fetchTerms]', e);
    return [];
  }
}
