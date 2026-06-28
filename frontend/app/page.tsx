import { fetchResources, fetchTerms, type Resource } from '@/lib/jsonapi';
import Filters from './_components/Filters';

// searchParams 駆動なので毎回サーバーで再評価する。
export const dynamic = 'force-dynamic';

type SP = Record<string, string | string[] | undefined>;

function pick(sp: SP, key: string): string {
  const v = sp[key];
  return typeof v === 'string' ? v : '';
}

export default async function Home({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const current = {
    type: pick(sp, 'type'),
    tech: pick(sp, 'tech'),
    aud: pick(sp, 'aud'),
    q: pick(sp, 'q'),
  };

  const [resources, techTerms, audTerms] = await Promise.all([
    fetchResources(current),
    fetchTerms('tech_stack'),
    fetchTerms('target_audience'),
  ]);

  return (
    <main className="wrap">
      <h1>技術リソース検索</h1>
      <p className="lead">
        技術書・記事を tech_stack / 対象者 / キーワードで絞り込み（ヘッドレス Drupal · JSON:API）
      </p>

      <Filters techTerms={techTerms} audTerms={audTerms} current={current} />

      <p className="count">{resources.length} 件</p>

      {resources.length === 0 ? (
        <div className="empty">該当するリソースがありません。条件を変えてください。</div>
      ) : (
        <div className="grid">
          {resources.map((r) => (
            <ResourceCard key={`${r.type}-${r.id}`} r={r} />
          ))}
        </div>
      )}
    </main>
  );
}

function ResourceCard({ r }: { r: Resource }) {
  return (
    <article className="card">
      <span className={`badge ${r.type}`}>{r.type === 'book' ? '技術書籍' : '技術記事'}</span>
      <h2>
        {r.url ? (
          <a href={r.url} target="_blank" rel="noreferrer">
            {r.title}
          </a>
        ) : (
          r.title
        )}
      </h2>
      {r.subtitle && <div className="sub">{r.subtitle}</div>}
      {r.description && <p className="desc">{r.description}</p>}

      <div className="tags">
        {r.techStack.map((t) => (
          <span key={t} className="tag">
            {t}
          </span>
        ))}
        {r.audience.map((a) => (
          <span key={a} className="tag aud">
            {a}
          </span>
        ))}
      </div>

      <div className="scores">
        <span>
          可読性 <b>{r.avgReadability ?? '—'}</b>
        </span>
        <span>
          実用性 <b>{r.avgPracticality ?? '—'}</b>
        </span>
      </div>
    </article>
  );
}
