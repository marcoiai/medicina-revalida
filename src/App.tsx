import React, { useEffect, useMemo, useRef, useState } from "react";
import "materialize-css/dist/css/materialize.min.css";

type AlternativeLetter = "A" | "B" | "C" | "D" | "E";

type Question = {
  id: number;
  area: string;
  tema: string;
  dificuldade: string;
  enunciado: string;
  alternativas: Record<AlternativeLetter, string>;
  gabarito: AlternativeLetter;
  comentario: string;
};

type DashboardUser = {
  id: string;
  email: string;
  name: string;
  avatar_url?: string;
  is_admin: boolean;
  is_active?: boolean;
  question_text_size?: number;
  monthly_question_limit?: number;
};

type SystemUser = {
  id: string;
  email: string;
  name: string;
  avatar_url?: string;
  is_admin: boolean;
  is_active: boolean;
  email_verified_at?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
  simulations_count: number;
  monthly_question_limit: number;
};

type AreaPerformanceItem = {
  area: string;
  total: number;
  answered: number;
  correct: number;
  accuracy: number;
};

type AdminFormState = {
  area: string;
  tema: string;
  dificuldade: string;
  enunciado: string;
  A: string;
  B: string;
  C: string;
  D: string;
  E: string;
  gabarito: string;
  comentario: string;
};

type NavItem = {
  key: string;
  label: string;
  icon: string;
  adminOnly?: boolean;
};

type SimulationStatus = "completed" | "stopped" | "expired";

type SimulationRecord = {
  id: string;
  user_id?: string;
  title: string;
  area: string;
  status: SimulationStatus;
  started_at: string;
  ended_at: string;
  duration_seconds: number;
  elapsed_seconds: number;
  total_questions: number;
  answered_questions: number;
  correct_questions: number;
  accuracy: number;
};

const INITIAL_QUESTIONS: Question[] = [
  {
    id: 1,
    area: "Clínica Médica",
    tema: "Diabetes",
    dificuldade: "Média",
    enunciado:
      "Homem de 58 anos, diabético tipo 2, chega à UBS com HbA1c de 9,2% apesar de metformina em dose máxima. Tem doença cardiovascular aterosclerótica estabelecida. Qual classe medicamentosa deve ser priorizada pelo benefício cardiovascular?",
    alternativas: {
      A: "Sulfonilureia",
      B: "Inibidor de SGLT2 ou agonista de GLP-1 com benefício cardiovascular",
      C: "Insulina NPH obrigatoriamente",
      D: "Acarbose",
      E: "Suspender metformina e observar",
    },
    gabarito: "B",
    comentario:
      "Em paciente com diabetes tipo 2 e doença cardiovascular estabelecida, recomenda-se priorizar fármacos com benefício cardiovascular demonstrado, como inibidores de SGLT2 ou agonistas de GLP-1, conforme perfil clínico.",
  },
  {
    id: 2,
    area: "Pediatria",
    tema: "Desidratação",
    dificuldade: "Fácil",
    enunciado:
      "Criança de 2 anos com diarreia aguda, irritada, olhos fundos, sede intensa e sinal da prega que desaparece lentamente. Qual é a conduta inicial mais adequada na atenção básica?",
    alternativas: {
      A: "Antibiótico empírico para todos os casos",
      B: "Soro de reidratação oral em plano B",
      C: "Jejum absoluto por 24 horas",
      D: "Antiemético e alta sem observação",
      E: "Corticoide oral",
    },
    gabarito: "B",
    comentario:
      "Os sinais descritos sugerem alguma desidratação. A conduta é reidratação oral supervisionada, geralmente plano B, mantendo alimentação e aleitamento quando aplicável.",
  },
  {
    id: 3,
    area: "GO",
    tema: "Pré-natal",
    dificuldade: "Fácil",
    enunciado:
      "Gestante de 10 semanas comparece à primeira consulta de pré-natal. Qual exame deve ser solicitado rotineiramente nesse momento?",
    alternativas: {
      A: "Colonoscopia",
      B: "Sorologias, tipagem sanguínea, hemograma, urina tipo 1 e glicemia de jejum",
      C: "Tomografia de abdome",
      D: "Teste ergométrico",
      E: "Densitometria óssea",
    },
    gabarito: "B",
    comentario:
      "No início do pré-natal, são solicitados exames básicos para rastrear anemia, infecções, incompatibilidade Rh, alterações urinárias e diabetes, entre outros.",
  },
  {
    id: 4,
    area: "Cirurgia",
    tema: "Abdome agudo",
    dificuldade: "Fácil",
    enunciado:
      "Paciente jovem apresenta dor abdominal que iniciou em região periumbilical e migrou para fossa ilíaca direita, associada a náuseas e febre baixa. Qual diagnóstico é mais provável?",
    alternativas: {
      A: "Apendicite aguda",
      B: "Pancreatite crônica",
      C: "Diverticulite de sigmoide típica",
      D: "Colecistite alitiásica crônica",
      E: "Hepatite viral isolada",
    },
    gabarito: "A",
    comentario:
      "Dor migratória para fossa ilíaca direita, náuseas, anorexia e febre baixa compõem quadro clássico de apendicite aguda.",
  },
  {
    id: 5,
    area: "Preventiva/SUS",
    tema: "Atenção Primária",
    dificuldade: "Fácil",
    enunciado:
      "Na Estratégia Saúde da Família, qual atributo da Atenção Primária está relacionado ao acompanhamento do usuário ao longo do tempo?",
    alternativas: {
      A: "Longitudinalidade",
      B: "Terciarização",
      C: "Fragmentação",
      D: "Judicialização",
      E: "Centralização hospitalar",
    },
    gabarito: "A",
    comentario:
      "Longitudinalidade é o vínculo e acompanhamento contínuo do usuário pela equipe ao longo do tempo, elemento central da APS.",
  },
  {
    id: 6,
    area: "Clínica Médica",
    tema: "Hipertensão",
    dificuldade: "Média",
    enunciado:
      "Mulher de 64 anos, hipertensa, diabética, apresenta PA persistente de 158/96 mmHg em medidas repetidas. Já usa losartana em dose adequada. Qual associação é uma opção racional inicial?",
    alternativas: {
      A: "Associar outro bloqueador do receptor de angiotensina",
      B: "Associar diurético tiazídico ou bloqueador de canal de cálcio",
      C: "Suspender todos os anti-hipertensivos",
      D: "Usar antibiótico profilático",
      E: "Iniciar anticoagulação plena",
    },
    gabarito: "B",
    comentario:
      "Quando a monoterapia não controla a pressão, combinações frequentes incluem bloqueador do sistema renina-angiotensina com tiazídico ou bloqueador de canal de cálcio.",
  },
  {
    id: 7,
    area: "Pediatria",
    tema: "Imunização",
    dificuldade: "Média",
    enunciado:
      "Lactente de 2 meses comparece à UBS para vacinação de rotina. Qual vacina faz parte do calendário nessa idade?",
    alternativas: {
      A: "Tríplice viral",
      B: "Pentavalente",
      C: "HPV",
      D: "Febre amarela obrigatoriamente em todo território aos 2 meses",
      E: "dT adulto",
    },
    gabarito: "B",
    comentario:
      "A vacina pentavalente é uma das vacinas aplicadas no início do calendário infantil, incluindo doses aos 2 meses no calendário de rotina.",
  },
  {
    id: 8,
    area: "GO",
    tema: "Sangramento uterino",
    dificuldade: "Média",
    enunciado:
      "Mulher de 55 anos, menopausa há 4 anos, apresenta sangramento vaginal. Qual deve ser a principal preocupação diagnóstica inicial?",
    alternativas: {
      A: "Sangramento normal da menopausa",
      B: "Neoplasia endometrial ou lesão endometrial significativa",
      C: "Ovulação tardia fisiológica",
      D: "Puberdade precoce",
      E: "Dispepsia funcional",
    },
    gabarito: "B",
    comentario:
      "Sangramento pós-menopausa deve ser investigado, com atenção especial para câncer de endométrio e outras alterações endometriais.",
  },
  {
    id: 9,
    area: "Cirurgia",
    tema: "Trauma",
    dificuldade: "Média",
    enunciado:
      "Paciente vítima de trauma chega instável, com via aérea pérvia, respiração presente e sinais de choque. Segundo o atendimento inicial ao trauma, qual etapa deve ser priorizada após A e B?",
    alternativas: {
      A: "Avaliação da circulação e controle de hemorragia",
      B: "Exame dermatológico completo",
      C: "Alta após analgesia",
      D: "Colonoscopia",
      E: "Teste ergométrico",
    },
    gabarito: "A",
    comentario:
      "No ABCDE do trauma, após via aérea e respiração, avalia-se circulação, perfusão e controle de hemorragias potencialmente fatais.",
  },
  {
    id: 10,
    area: "Preventiva/SUS",
    tema: "Vigilância epidemiológica",
    dificuldade: "Média",
    enunciado:
      "Caso suspeito de doença de notificação compulsória é identificado em uma UBS. Qual é a conduta correta?",
    alternativas: {
      A: "Notificar apenas após confirmação laboratorial final",
      B: "Notificar conforme prazo definido, mesmo na suspeita quando indicado",
      C: "Não registrar para evitar alarme social",
      D: "Encaminhar sem registro",
      E: "Aguardar 6 meses",
    },
    gabarito: "B",
    comentario:
      "Muitas doenças de notificação compulsória exigem notificação já na suspeita, respeitando os prazos definidos pelas normas vigentes.",
  },
];

const STORAGE_KEY = "revalida_material_dashboard_v1";
const SIMULADO_BASELINE_STORAGE_KEY = "revalida_simulado_baseline_v1";
const SIMULATION_HISTORY_STORAGE_PREFIX = "revalida_simulations_v1";
const DEFAULT_SIMULADO_DURATION_MINUTES = 30;
const MIN_SIMULADO_DURATION_MINUTES = 1;
const MAX_SIMULADO_DURATION_MINUTES = 120;
const DEFAULT_SIMULADO_QUESTION_COUNT = 15;
const MIN_SIMULADO_QUESTION_COUNT = 1;
const DEFAULT_QUESTION_TEXT_SIZE = 22;
const MIN_QUESTION_TEXT_SIZE = 18;
const MAX_QUESTION_TEXT_SIZE = 28;
const QUESTION_TEXT_SIZE_STEP = 2;
const QUESTIONS_PAGE_SIZE = 2000;
const MAX_MONTHLY_QUESTION_LIMIT = 5000;
const DEFAULT_MONTHLY_QUESTION_LIMIT = 5000;
const QUESTION_ORDER_SEED_KEY = "revalida_question_order_seed_v1";
const configuredApiBaseRaw = (import.meta.env.VITE_API_BASE_URL as string | undefined)?.trim();
const API_BASE_OVERRIDE_KEY = "revalida_api_base_url";

function normalizeApiBase(candidate?: string): string | null {
  if (!candidate) return null;

  const trimmed = candidate.trim().replace(/\/+$/, "");
  if (!trimmed) return null;

  if (/\/api$/i.test(trimmed)) return trimmed;

  try {
    const parsed = new URL(trimmed);
    return `${parsed.origin}/api`;
  } catch {
    return null;
  }
}

const configuredApiBase = normalizeApiBase(configuredApiBaseRaw);
const sameOriginApiBase = typeof window !== "undefined" ? `${window.location.origin}/api` : null;

function getRuntimeApiBase(): string | null {
  if (typeof window === "undefined") return null;

  const search = new URLSearchParams(window.location.search);
  const fromQuery = normalizeApiBase(search.get("apiBase") ?? undefined);

  if (fromQuery) {
    window.localStorage.setItem(API_BASE_OVERRIDE_KEY, fromQuery);

    // Remove apiBase from URL after persisting it, so shared links stay clean.
    search.delete("apiBase");
    const newSearch = search.toString();
    const nextUrl = `${window.location.pathname}${newSearch ? `?${newSearch}` : ""}${window.location.hash}`;
    window.history.replaceState({}, document.title, nextUrl);

    return fromQuery;
  }

  return normalizeApiBase(window.localStorage.getItem(API_BASE_OVERRIDE_KEY) ?? undefined);
}

const runtimeApiBase = getRuntimeApiBase();
const API_BASE_CANDIDATES = Array.from(new Set([
  runtimeApiBase,
  sameOriginApiBase,
  configuredApiBase,
  "http://127.0.0.1:8001/api",
  "http://localhost:8001/api",
  "http://127.0.0.1:8000/api",
  "http://localhost:8000/api",
].filter(Boolean) as string[]));

type ApiRequestListener = (count: number) => void;

let activeApiRequestCount = 0;
const apiRequestListeners = new Set<ApiRequestListener>();

function notifyApiRequestListeners() {
  for (const listener of apiRequestListeners) {
    listener(activeApiRequestCount);
  }
}

function subscribeApiRequestCount(listener: ApiRequestListener) {
  apiRequestListeners.add(listener);
  listener(activeApiRequestCount);
  return () => {
    apiRequestListeners.delete(listener);
  };
}

function shouldTryNextApiBase(response: Response): boolean {
  if (response.ok) return false;

  const contentType = (response.headers.get("content-type") || "").toLowerCase();
  const isHtmlError = contentType.includes("text/html");
  const status = response.status;

  // Wrong target (e.g. Vite dev server) usually replies HTML + 4xx/5xx.
  return isHtmlError && status >= 400;
}

async function apiFetch(path: string, init?: RequestInit): Promise<Response> {
  activeApiRequestCount += 1;
  notifyApiRequestListeners();

  try {
    let lastError: unknown;
    let lastResponse: Response | null = null;

    for (const base of API_BASE_CANDIDATES) {
      try {
        const headers = new Headers(init?.headers);
        if (!headers.has("Accept")) headers.set("Accept", "application/json");

        const response = await fetch(`${base}${path}`, { ...init, headers });
        if (shouldTryNextApiBase(response)) {
          lastResponse = response;
          continue;
        }

        return response;
      } catch (error) {
        lastError = error;
      }
    }

    if (lastResponse) return lastResponse;

    const baseHints = API_BASE_CANDIDATES.map((base) => base.replace(/\/api$/, "")).join(" | ");
    const errorText = lastError instanceof Error ? lastError.message : "Unknown network error";
    throw new Error(`Não consegui conectar à API (${baseHints}). Detalhe: ${errorText}`);
  } finally {
    activeApiRequestCount = Math.max(0, activeApiRequestCount - 1);
    notifyApiRequestListeners();
  }
}

const NAV_ITEMS: NavItem[] = [
  { key: "dashboard", label: "Dashboard", icon: "dashboard" },
  { key: "questoes", label: "Questões", icon: "quiz" },
  { key: "simulado", label: "Simulados", icon: "timer" },
  { key: "ranking", label: "Ranking", icon: "emoji_events" },
  { key: "admin", label: "Admin", icon: "settings", adminOnly: true },
  { key: "usuarios", label: "Usuários", icon: "group", adminOnly: true },
  { key: "planos", label: "Planos", icon: "credit_card" },
];

const RANKING_MOCK = [
  { nome: "Ana", acertos: 86, questoes: 100 },
  { nome: "Bruno", acertos: 78, questoes: 100 },
  { nome: "Carla", acertos: 72, questoes: 100 },
];

function Icon({ children, className = "" }: { children: React.ReactNode; className?: string }) {
  return <i className={`material-icons ${className}`}>{children}</i>;
}

function StatCard({
  title,
  value,
  icon,
  color,
  helper,
}: {
  title: string;
  value: React.ReactNode;
  icon: string;
  color: string;
  helper: string;
}) {
  return (
    <div className="card stat-card">
      <div className="card-content">
        <div className={`stat-icon ${color}`}>
          <Icon>{icon}</Icon>
        </div>
        <span className="stat-title">{title}</span>
        <h3>{value}</h3>
        <p>{helper}</p>
      </div>
    </div>
  );
}

function AreaBar({ item }: { item: AreaPerformanceItem }) {
  return (
    <div className="area-row">
      <div className="area-head">
        <div>
          <strong>{item.area}</strong>
          <span>{item.answered}/{item.total} respondidas · {item.correct} acertos</span>
        </div>
        <b>{item.accuracy}%</b>
      </div>
      <div className="progress material-progress">
        <div className="determinate indigo" style={{ width: `${item.accuracy}%` }} />
      </div>
    </div>
  );
}

function RecentErrorsCarousel({
  items,
  emptyMessage,
}: {
  items: Question[];
  emptyMessage: string;
}) {
  const recentItems = useMemo(() => [...items.slice(-4)].reverse(), [items]);
  const [currentIndex, setCurrentIndex] = useState(0);

  useEffect(() => {
    setCurrentIndex((prev) => Math.min(prev, Math.max(recentItems.length - 1, 0)));
  }, [recentItems.length]);

  if (!recentItems.length) {
    return (
      <div className="error-list">
        <p style={{ color: "#90a4ae" }}>{emptyMessage}</p>
      </div>
    );
  }

  const currentItem = recentItems[currentIndex];

  return (
    <div className="error-list">
      <div className="error-nav">
        <span className="error-nav-count">
          {currentIndex + 1} de {recentItems.length}
        </span>
        <div className="error-nav-actions">
          <button
            type="button"
            className="md-btn outline"
            onClick={() => setCurrentIndex((prev) => Math.max(prev - 1, 0))}
            disabled={currentIndex === 0}
          >
            Anterior
          </button>
          <button
            type="button"
            className="md-btn outline"
            onClick={() => setCurrentIndex((prev) => Math.min(prev + 1, recentItems.length - 1))}
            disabled={currentIndex === recentItems.length - 1}
          >
            Próximo
          </button>
        </div>
      </div>

      <div className="result-box">
        <h4>
          <Icon>cancel</Icon>
          Errado · Gabarito {currentItem.gabarito}
        </h4>
        <p>{currentItem.comentario}</p>
      </div>
    </div>
  );
}

function AppStyles() {
  return (
    <style>{`
      :root {
        --bg: #f4f6fb;
        --surface: #ffffff;
        --text: #263238;
        --muted: #78909c;
        --primary: #3f51b5;
        --primary-dark: #303f9f;
        --border: #e8edf5;
      }
      * { box-sizing: border-box; }
      body { margin: 0; background: var(--bg); color: var(--text); font-family: Roboto, Arial, sans-serif; }
      .app-shell { min-height: 100vh; background: var(--bg); }
      .login-page { min-height: 100vh; display: grid; grid-template-columns: 1.1fr 0.9fr; background: #fff; }
      .login-hero { position: relative; overflow: hidden; padding: 64px; background: linear-gradient(135deg, #283593, #5c6bc0); color: #fff; display: flex; flex-direction: column; justify-content: space-between; }
      .login-hero::after { content: ''; position: absolute; width: 520px; height: 520px; border-radius: 50%; background: rgba(255,255,255,.12); right: -160px; bottom: -160px; pointer-events: none; }
      .brand-row { display: flex; align-items: center; gap: 16px; position: relative; z-index: 1; }
      .brand-logo { width: 56px; height: 56px; border-radius: 18px; background: rgba(255,255,255,.16); display: grid; place-items: center; box-shadow: 0 16px 36px rgba(0,0,0,.18); }
      .brand-row h1 { font-size: 28px; margin: 0; font-weight: 900; letter-spacing: -0.04em; }
      .brand-row p { margin: 2px 0 0; opacity: .75; }
      .hero-copy { position: relative; z-index: 1; max-width: 640px; }
      .hero-copy h2 { font-size: clamp(42px, 5vw, 72px); line-height: .95; font-weight: 900; letter-spacing: -0.065em; margin: 0 0 24px; }
      .hero-copy p { font-size: 20px; line-height: 1.7; opacity: .86; max-width: 560px; }
      .hero-stats { position: relative; z-index: 1; display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; max-width: 650px; }
      .hero-stat { padding: 18px; border-radius: 18px; background: rgba(255,255,255,.12); backdrop-filter: blur(10px); }
      .hero-stat b { display: block; font-size: 28px; line-height: 1; }
      .hero-stat span { opacity: .76; font-size: 13px; }
      .login-panel { position: relative; z-index: 5; display: grid; place-items: center; padding: 48px; background: #fff; }
      .login-card { position: relative; z-index: 10; width: 100%; max-width: 440px; box-shadow: none; border: 1px solid var(--border); border-radius: 28px; overflow: hidden; }
      .login-card .card-content { position: relative; z-index: 15; padding: 38px; }
      .login-card h3 { margin: 0 0 8px; font-size: 34px; font-weight: 900; letter-spacing: -0.045em; color: #1f2937; }
      .login-card .subtitle { margin: 0 0 30px; color: var(--muted); line-height: 1.6; }
      .global-loader { position: fixed; top: 0; left: 0; right: 0; z-index: 9999; pointer-events: none; }
      .global-loader-track { height: 3px; background: rgba(63,81,181,.12); overflow: hidden; }
      .global-loader-bar { height: 100%; width: 38%; background: linear-gradient(90deg, transparent, var(--primary), transparent); animation: loader-slide 1.1s ease-in-out infinite; }
      .global-loader-label { position: absolute; top: 10px; right: 16px; background: rgba(255,255,255,.98); border: 1px solid var(--border); color: var(--primary); border-radius: 999px; padding: 7px 12px; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 10px 28px rgba(17,24,39,.08); font-size: 12px; font-weight: 900; }
      .mini-spinner { width: 14px; height: 14px; border-radius: 50%; border: 2px solid rgba(63,81,181,.2); border-top-color: var(--primary); animation: spin .8s linear infinite; }
      .loading-inline { display: inline-flex; align-items: center; gap: 8px; color: var(--muted); font-weight: 700; }
      .loading-block { display: inline-flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 14px; background: #f8faff; border: 1px solid var(--border); color: #455a64; font-weight: 800; }
      .field-label { display: block; margin: 0 0 8px; color: #546e7a; font-weight: 700; font-size: 13px; }
      .md-input { position: relative; z-index: 20; width: 100%; height: 54px; border: 1px solid var(--border) !important; border-radius: 14px !important; padding: 0 16px !important; outline: none !important; font-size: 15px !important; color: #1f2937 !important; caret-color: #1f2937 !important; background: #fafbff !important; transition: .2s; box-shadow: none !important; pointer-events: auto; }
      .md-input:focus { border-color: var(--primary) !important; box-shadow: 0 0 0 4px rgba(63,81,181,.12) !important; background: #fff !important; }
      .md-input::placeholder { color: #90a4ae !important; opacity: 1; }
      .md-input:-webkit-autofill,
      .md-input:-webkit-autofill:hover,
      .md-input:-webkit-autofill:focus {
        -webkit-text-fill-color: #1f2937 !important;
        transition: background-color 9999s ease-out 0s;
      }
      .md-btn { height: 48px; border: 0; border-radius: 14px; padding: 0 22px; display: inline-flex; align-items: center; justify-content: center; gap: 10px; font-weight: 800; text-transform: none; letter-spacing: 0; cursor: pointer; transition: .18s; }
      .md-btn.primary { background: var(--primary); color: #fff; box-shadow: 0 8px 20px rgba(63,81,181,.22); }
      .md-btn.primary:hover { background: var(--primary-dark); }
      .md-btn.outline { background: #fff; color: #455a64; border: 1px solid var(--border); }
      .md-btn.outline:hover { background: #f7f9fc; }
      .md-btn.block { width: 100%; }
      .layout { min-height: 100vh; display: grid; grid-template-columns: 280px 1fr; }
      .sidebar { background: #fff; border-right: 1px solid var(--border); padding: 24px 18px; position: sticky; top: 0; height: 100vh; display: flex; flex-direction: column; }
      .sidebar-brand { display: flex; align-items: center; gap: 14px; padding: 8px 10px 28px; }
      .sidebar-brand .logo { width: 48px; height: 48px; border-radius: 16px; background: var(--primary); color: #fff; display: grid; place-items: center; box-shadow: 0 12px 24px rgba(63,81,181,.28); }
      .sidebar-brand h2 { margin: 0; font-size: 23px; font-weight: 900; letter-spacing: -0.04em; }
      .sidebar-brand p { margin: 2px 0 0; color: var(--muted); font-size: 13px; }
      .nav-list { display: grid; gap: 8px; }
      .nav-btn { border: 0; background: transparent; min-height: 54px; border-radius: 16px; padding: 0 16px; display: flex; align-items: center; gap: 14px; font-weight: 800; color: #607d8b; text-align: left; cursor: pointer; transition: .18s; }
      .nav-btn:hover { background: #f5f7fb; color: #263238; }
      .nav-btn.active { background: #e8eaf6; color: var(--primary); }
      .nav-btn i { font-size: 23px; }
      .sidebar-user { margin-top: auto; border: 1px solid var(--border); border-radius: 20px; padding: 16px; background: #fafbff; }
      .sidebar-user b { display: block; margin-bottom: 3px; }
      .sidebar-user span { display: block; color: var(--muted); font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .sidebar-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 14px; }
      .content { min-width: 0; padding: 28px; }
      .topbar { display: flex; align-items: center; justify-content: space-between; gap: 24px; margin-bottom: 26px; }
      .page-title h1 { margin: 0; font-size: 34px; font-weight: 900; letter-spacing: -0.05em; color: #1f2937; }
      .page-title p { margin: 6px 0 0; color: var(--muted); font-size: 15px; }
      .simulado-toolbar { display: flex; align-items: center; gap: 10px; }
      .simulado-badge { height: 34px; border-radius: 999px; background: #fff3e0; color: #ef6c00; border: 1px solid #ffe0b2; padding: 0 12px; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 900; }
      .simulado-config-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
      .simulado-config-field { display: grid; gap: 10px; }
      .simulado-config-field span { color: #455a64; font-size: 13px; font-weight: 800; }
      .simulado-config-field input { width: 100%; height: 50px; border-radius: 16px; border: 1px solid var(--border); background: #fff; padding: 0 16px; font-size: 18px; font-weight: 800; color: #1f2937; outline: none; box-sizing: border-box; }
      .simulado-config-field input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(63,81,181,.08); }
      .simulado-config-field input:disabled { background: #f5f7fb; color: #90a4ae; cursor: not-allowed; }
      .simulado-config-field small { color: var(--muted); font-size: 12px; line-height: 1.45; }
      .simulado-config-summary { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
      .simulado-config-pill { min-height: 38px; border-radius: 999px; background: #eef2ff; color: var(--primary); padding: 0 14px; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 900; }
      .simulado-config-pill.active { background: #e8f5e9; color: #2e7d32; }
      .simulado-config-actions { display: flex; align-items: center; gap: 10px; margin-top: 18px; }
      .mobile-nav { display: none; gap: 8px; overflow-x: auto; padding-bottom: 12px; margin-bottom: 18px; }
      .mobile-nav .nav-btn { white-space: nowrap; background: #fff; border: 1px solid var(--border); }
      .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 22px; }
      .card { border-radius: 22px; box-shadow: 0 8px 26px rgba(17, 24, 39, .06); border: 1px solid var(--border); }
      .card .card-content { padding: 24px; }
      .stat-card { margin: 0; }
      .stat-card .card-content { position: relative; min-height: 146px; }
      .stat-icon { position: absolute; right: 20px; top: 20px; width: 48px; height: 48px; border-radius: 16px; display: grid; place-items: center; }
      .stat-icon.indigo { background: #e8eaf6; color: #3f51b5; }
      .stat-icon.green { background: #e8f5e9; color: #43a047; }
      .stat-icon.blue { background: #e3f2fd; color: #1e88e5; }
      .stat-icon.orange { background: #fff3e0; color: #fb8c00; }
      .stat-title { display: block; color: var(--muted); font-size: 12px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; }
      .stat-card h3 { margin: 18px 0 8px; font-size: 40px; font-weight: 900; letter-spacing: -0.06em; color: #1f2937; }
      .stat-card p { color: var(--muted); margin: 0; font-size: 13px; }
      .dashboard-grid { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 22px; align-items: start; }
      .section-title { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
      .section-title h2 { margin: 0; font-size: 22px; font-weight: 900; letter-spacing: -0.035em; }
      .section-title p { margin: 4px 0 0; color: var(--muted); }
      .area-row { padding: 16px 0; border-bottom: 1px solid #eef2f7; }
      .area-row:last-child { border-bottom: 0; }
      .area-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 10px; }
      .area-head strong { display: block; font-size: 15px; }
      .area-head span { display: block; margin-top: 2px; color: var(--muted); font-size: 13px; }
      .area-head b { color: #37474f; }
      .material-progress { height: 10px; border-radius: 999px; background: #eef2ff; margin: 0; overflow: hidden; }
      .material-progress .determinate { border-radius: 999px; }
      .donut-card { text-align: center; }
      .donut { width: 184px; height: 184px; margin: 10px auto 18px; border-radius: 50%; display: grid; place-items: center; }
      .donut-inner { width: 128px; height: 128px; border-radius: 50%; background: #fff; display: grid; place-items: center; box-shadow: inset 0 0 0 1px #edf1f7; }
      .donut-inner b { display: block; font-size: 36px; font-weight: 900; letter-spacing: -0.06em; }
      .donut-inner span { color: var(--muted); font-size: 12px; }
      .error-list { display: grid; gap: 10px; }
      .error-list .result-box { margin-top: 0; }
      .error-nav { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
      .error-nav-count { color: var(--muted); font-size: 12px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; }
      .error-nav-actions { display: flex; gap: 8px; }
      .error-nav-actions .md-btn { min-width: 110px; }
      .error-item { padding: 14px; border-radius: 16px; background: #fafbff; border: 1px solid var(--border); }
      .error-item b { display: block; font-size: 14px; }
      .error-item span { color: var(--muted); font-size: 12px; }
      .error-item .error-explainer { margin: 8px 0 0; color: #455a64; font-size: 13px; line-height: 1.5; }
      .simulado-metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 22px; }
      .simulado-metric { border: 1px solid var(--border); border-radius: 18px; background: #fff; padding: 16px; }
      .simulado-metric span { display: block; color: var(--muted); font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
      .simulado-metric b { display: block; margin-top: 8px; font-size: 28px; font-weight: 900; letter-spacing: -0.05em; color: #1f2937; }
      .simulado-metric p { margin: 6px 0 0; color: #607d8b; font-size: 12px; }
      .simulado-history-grid { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(320px, 0.85fr); gap: 22px; align-items: start; }
      .simulado-history-list { display: grid; gap: 12px; }
      .simulado-history-item { width: 100%; text-align: left; border: 1px solid var(--border); background: #fff; border-radius: 18px; padding: 16px; display: flex; align-items: center; justify-content: space-between; gap: 16px; cursor: pointer; transition: .18s; }
      .simulado-history-item:hover { background: #fafbff; border-color: #c5cae9; }
      .simulado-history-item.active { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(63,81,181,.08); }
      .simulado-history-item h3 { margin: 0 0 6px; font-size: 16px; font-weight: 900; letter-spacing: -0.03em; }
      .simulado-history-item p { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.45; }
      .simulado-history-main { display: grid; gap: 8px; }
      .simulado-history-side { display: grid; gap: 14px; }
      .simulado-detail { display: grid; gap: 12px; }
      .simulado-detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
      .simulado-detail-card { border: 1px solid var(--border); border-radius: 16px; background: #fafbff; padding: 14px; }
      .simulado-detail-card span { display: block; color: var(--muted); font-size: 12px; font-weight: 800; }
      .simulado-detail-card b { display: block; margin-top: 4px; font-size: 20px; font-weight: 900; letter-spacing: -0.04em; color: #1f2937; }
      .simulado-detail-card p { margin: 4px 0 0; color: #607d8b; font-size: 12px; }
      .usuarios-metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 22px; }
      .usuarios-grid { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(320px, 0.85fr); gap: 22px; align-items: start; }
      .usuarios-side { display: grid; gap: 14px; }
      .usuarios-actions { display: flex; align-items: center; gap: 12px; min-width: min(100%, 460px); justify-content: flex-end; }
      .usuarios-search { max-width: 280px; }
      .usuarios-list { display: grid; gap: 12px; }
      .usuario-item { width: 100%; text-align: left; border: 1px solid var(--border); background: #fff; border-radius: 18px; padding: 14px; display: flex; align-items: center; justify-content: space-between; gap: 16px; cursor: pointer; transition: .18s; }
      .usuario-item:hover { background: #fafbff; border-color: #c5cae9; }
      .usuario-item.active { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(63,81,181,.08); }
      .usuario-item-main { display: flex; align-items: center; gap: 14px; min-width: 0; }
      .usuario-item-copy { min-width: 0; }
      .usuario-item-copy h3 { margin: 0 0 4px; font-size: 16px; font-weight: 900; letter-spacing: -0.03em; }
      .usuario-item-copy p { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.45; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 42vw; }
      .usuario-avatar, .usuario-profile-avatar { width: 52px; height: 52px; border-radius: 50%; object-fit: cover; flex: 0 0 auto; }
      .usuario-avatar.fallback, .usuario-profile-avatar.fallback { background: var(--primary); color: #fff; display: grid; place-items: center; font-weight: 900; font-size: 20px; }
      .usuario-item-side { display: grid; justify-items: end; gap: 8px; }
      .usuario-item-side strong { font-size: 24px; font-weight: 900; letter-spacing: -0.05em; color: #1f2937; }
      .usuario-detail { display: grid; gap: 12px; }
      .usuario-profile { display: flex; align-items: center; gap: 14px; padding: 4px 0 8px; }
      .usuario-profile h3 { margin: 0 0 4px; font-size: 22px; font-weight: 900; letter-spacing: -0.04em; }
      .usuario-profile p { margin: 0; color: var(--muted); }
      .usuario-badges { display: flex; flex-wrap: wrap; gap: 8px; }
      .usuario-detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
      .status-pill { height: 30px; border-radius: 999px; padding: 0 12px; display: inline-flex; align-items: center; font-size: 12px; font-weight: 900; letter-spacing: .03em; }
      .status-pill.green { background: #e8f5e9; color: #2e7d32; }
      .status-pill.orange { background: #fff3e0; color: #ef6c00; }
      .status-pill.red { background: #ffebee; color: #c62828; }
      .question-layout { display: grid; grid-template-columns: minmax(0, 1fr) 330px; gap: 22px; align-items: start; }
      .area-tabs { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 12px; margin-bottom: 20px; }
      .area-tab { height: 42px; border-radius: 999px; border: 1px solid var(--border); background: #fff; color: #607d8b; padding: 0 18px; font-weight: 800; cursor: pointer; white-space: nowrap; }
      .area-tab.active { background: var(--primary); color: #fff; border-color: var(--primary); box-shadow: 0 8px 18px rgba(63,81,181,.18); }
      .question-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 22px; }
      .chip.md-chip { height: 30px; line-height: 30px; border-radius: 999px; font-weight: 800; }
      .question-counter { margin-left: auto; padding: 8px 14px; border-radius: 999px; background: #f5f7fb; color: #607d8b; font-size: 13px; font-weight: 800; }
      .question-text { font-size: 22px; line-height: 1.55; margin: 0 0 26px; font-weight: 800; letter-spacing: -0.035em; color: #1f2937; }
      .question-text-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 0 14px; }
      .question-text-toolbar span { color: var(--muted); font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
      .question-text-controls { display: inline-flex; align-items: center; gap: 8px; }
      .question-text-size { min-width: 58px; text-align: center; font-size: 12px; font-weight: 900; color: #455a64; background: #f5f7fb; border-radius: 999px; padding: 8px 10px; }
      .question-text-btn { width: 34px; height: 34px; border-radius: 50%; border: 1px solid var(--border); background: #fff; color: #455a64; font-size: 20px; font-weight: 900; line-height: 1; cursor: pointer; display: grid; place-items: center; transition: .18s; }
      .question-text-btn:hover { background: #f5f7fb; border-color: #c5cae9; }
      .question-text-btn:disabled { opacity: .45; cursor: not-allowed; }
      .answers { display: grid; gap: 12px; }
      .answer-btn { width: 100%; border: 1px solid var(--border); background: #fff; border-radius: 18px; min-height: 68px; display: flex; align-items: center; gap: 16px; padding: 14px 16px; text-align: left; cursor: pointer; transition: .18s; }
      .answer-btn:hover { border-color: #c5cae9; background: #fafbff; }
      .answer-btn.selected { border-color: var(--primary); background: #eef2ff; box-shadow: 0 0 0 3px rgba(63,81,181,.1); }
      .answer-btn.correct { border-color: #43a047; background: #e8f5e9; }
      .answer-btn.wrong { border-color: #e53935; background: #ffebee; }
      .answer-letter { width: 40px; height: 40px; border-radius: 14px; background: #eef2f7; color: #455a64; display: grid; place-items: center; font-weight: 900; flex: 0 0 auto; }
      .answer-btn.selected .answer-letter { background: var(--primary); color: #fff; }
      .answer-text { font-size: 15px; line-height: 1.5; font-weight: 600; color: #37474f; }
      .question-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 24px; padding-top: 22px; border-top: 1px solid var(--border); }
      .question-actions .right { display: flex; gap: 10px; }
      .result-box { margin-top: 22px; padding: 18px; border-radius: 18px; background: #fafbff; border: 1px solid var(--border); }
      .result-box h4 { display: flex; align-items: center; gap: 10px; margin: 0 0 10px; font-size: 18px; font-weight: 900; }
      .result-box p { margin: 0; color: #546e7a; line-height: 1.6; }
      .side-summary { display: grid; gap: 16px; }
      .mini-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
      .mini-stat { background: #fafbff; border: 1px solid var(--border); border-radius: 16px; padding: 14px; }
      .mini-stat span { color: var(--muted); font-size: 12px; font-weight: 800; }
      .mini-stat b { display: block; margin-top: 4px; font-size: 26px; font-weight: 900; letter-spacing: -0.05em; }
      .simple-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; }
      .plan-card.featured { border-color: #c5cae9; box-shadow: 0 14px 36px rgba(63,81,181,.14); }
      .admin-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
      .admin-form textarea { min-height: 120px; resize: vertical; padding: 14px 16px; }
      .span-2 { grid-column: span 2; }
      @media (max-width: 1100px) {
        .layout { grid-template-columns: 1fr; }
        .sidebar { display: none; }
        .mobile-nav { display: flex; }
        .dashboard-grid, .question-layout { grid-template-columns: 1fr; }
        .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .simulado-config-grid, .simulado-metrics, .simulado-history-grid, .usuarios-metrics, .usuarios-grid { grid-template-columns: 1fr; }
      }
      @media (max-width: 700px) {
        .login-page { grid-template-columns: 1fr; }
        .login-hero { display: none; }
        .login-panel { padding: 20px; min-height: 100vh; }
        .content { padding: 18px; }
        .topbar { display: block; }
        .simulado-toolbar { margin-top: 12px; }
        .page-title h1 { font-size: 30px; }
        .stat-grid, .simple-grid, .simulado-metrics, .simulado-detail-grid, .usuarios-metrics, .usuario-detail-grid { grid-template-columns: 1fr; }
        .question-actions { flex-direction: column; align-items: stretch; }
        .question-actions .right { display: grid; grid-template-columns: 1fr 1fr; }
        .error-nav { align-items: stretch; flex-direction: column; }
        .error-nav-actions { display: grid; grid-template-columns: 1fr 1fr; }
        .simulado-config-actions { flex-direction: column; align-items: stretch; }
        .md-btn { width: 100%; }
        .admin-form { grid-template-columns: 1fr; }
        .span-2 { grid-column: span 1; }
        .usuarios-actions { min-width: 0; width: 100%; flex-direction: column; align-items: stretch; }
        .usuarios-search { max-width: none; width: 100%; }
        .usuario-item { align-items: flex-start; flex-direction: column; }
        .usuario-item-side { justify-items: start; }
      }
      @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
      @keyframes loader-slide { 0% { transform: translateX(-100%); } 50% { transform: translateX(160%); } 100% { transform: translateX(320%); } }
    `}</style>
  );
}

async function readApiError(response: Response, fallback: string): Promise<string> {
  try {
    const payload = await response.json();
    if (typeof payload?.message === "string" && payload.message.trim()) return payload.message;
    if (typeof payload?.detail === "string" && payload.detail.trim()) return payload.detail;

    const validation = payload?.errors && typeof payload.errors === "object" ? Object.values(payload.errors).flat() : [];
    const firstValidationError = validation.find((item) => typeof item === "string");
    if (typeof firstValidationError === "string" && firstValidationError.trim()) return firstValidationError;
  } catch {
    // no-op: non-JSON responses are handled by fallback below
  }

  return `${fallback} (HTTP ${response.status})`;
}

async function loadQuestionBankTotal(): Promise<number> {
  const res = await apiFetch("/questions/summary");
  if (!res.ok) throw new Error("API offline");

  const payload = await res.json();
  const totalQuestions = Number((payload as Record<string, unknown>)?.total_questions ?? 0);
  return Number.isFinite(totalQuestions) ? Math.max(0, Math.floor(totalQuestions)) : 0;
}

function extractQuestionsFromPayload(payload: unknown): Question[] {
  if (Array.isArray(payload)) return payload as Question[];

  if (payload && typeof payload === "object") {
    const obj = payload as Record<string, unknown>;
    if (Array.isArray(obj.data)) return obj.data as Question[];
    if (Array.isArray(obj.questions)) return obj.questions as Question[];
  }

  return [];
}

async function loadAccessibleQuestions(limit: number, totalCount: number | null, userId?: string | null): Promise<Question[]> {
  const perPage = Math.max(1, Math.min(Math.floor(limit), QUESTIONS_PAGE_SIZE));
  const page = totalCount && totalCount > perPage ? getQuestionWindowPage(totalCount, perPage, userId) : 1;

  const res = await apiFetch(`/questions?page=${page}&per_page=${perPage}`);
  if (!res.ok) throw new Error("API offline");

  const payload = await res.json();
  const pageItems = extractQuestionsFromPayload(payload);

  // Non-paginated APIs return a plain array (or one list field).
  if (Array.isArray(payload)) {
    return shuffleQuestions(pageItems, getQuestionOrderSeed());
  }

  // De-duplicate by question id in case API overlaps pages.
  const deduped = new Map<number, Question>();
  for (const item of pageItems) {
    if (!item || typeof item.id !== "number") continue;
    deduped.set(item.id, item);
  }

  return shuffleQuestions(Array.from(deduped.values()).sort((a, b) => a.id - b.id), getQuestionOrderSeed());
}

function getSimulationStorageKey(userId?: string | null): string {
  return `${SIMULATION_HISTORY_STORAGE_PREFIX}_${userId || "guest"}`;
}

function extractSimulationRecordsFromPayload(payload: unknown): SimulationRecord[] {
  if (Array.isArray(payload)) return payload as SimulationRecord[];

  if (payload && typeof payload === "object") {
    const obj = payload as Record<string, unknown>;
    if (Array.isArray(obj.data)) return obj.data as SimulationRecord[];
    if (Array.isArray(obj.simulations)) return obj.simulations as SimulationRecord[];
  }

  return [];
}

function normalizeSimulationRecords(records: SimulationRecord[]): SimulationRecord[] {
  return records
    .filter((record) => Boolean(record?.id))
    .sort((a, b) => {
      const left = new Date(b.started_at || b.ended_at || 0).getTime();
      const right = new Date(a.started_at || a.ended_at || 0).getTime();
      return left - right;
    });
}

function formatSimulationDuration(totalSeconds: number): string {
  const safeSeconds = Math.max(0, Math.floor(totalSeconds));
  const hours = Math.floor(safeSeconds / 3600);
  const minutes = Math.floor((safeSeconds % 3600) / 60);
  const seconds = safeSeconds % 60;

  if (hours > 0) {
    return `${hours}h ${minutes.toString().padStart(2, "0")}m`;
  }

  if (minutes > 0) {
    return `${minutes}m ${seconds.toString().padStart(2, "0")}s`;
  }

  return `${seconds}s`;
}

function formatSimulationDate(value?: string | null): string {
  if (!value) return "Sem data";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "Sem data";

  return new Intl.DateTimeFormat("pt-BR", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(date);
}

function extractSystemUsersFromPayload(payload: unknown): SystemUser[] {
  if (Array.isArray(payload)) return payload as SystemUser[];

  if (payload && typeof payload === "object") {
    const obj = payload as Record<string, unknown>;
    if (Array.isArray(obj.data)) return obj.data as SystemUser[];
    if (Array.isArray(obj.users)) return obj.users as SystemUser[];
  }

  return [];
}

function normalizeSystemUsers(users: SystemUser[]): SystemUser[] {
  return users
    .map((user) => ({
      ...user,
      monthly_question_limit: normalizeMonthlyQuestionLimit(user.monthly_question_limit),
    }))
    .filter((user) => Boolean(user?.id))
    .sort((a, b) => {
      const left = new Date(b.created_at || b.updated_at || 0).getTime();
      const right = new Date(a.created_at || a.updated_at || 0).getTime();
      return left - right;
    });
}

function formatUserDate(value?: string | null): string {
  if (!value) return "Sem data";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "Sem data";

  return new Intl.DateTimeFormat("pt-BR", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(date);
}

function normalizeQuestionTextSize(value?: number | null): number {
  if (typeof value !== "number" || !Number.isFinite(value)) return DEFAULT_QUESTION_TEXT_SIZE;
  return Math.min(MAX_QUESTION_TEXT_SIZE, Math.max(MIN_QUESTION_TEXT_SIZE, Math.round(value)));
}

function normalizeSimuladoDurationMinutes(value?: number | null): number {
  if (typeof value !== "number" || !Number.isFinite(value)) return DEFAULT_SIMULADO_DURATION_MINUTES;
  return Math.min(MAX_SIMULADO_DURATION_MINUTES, Math.max(MIN_SIMULADO_DURATION_MINUTES, Math.round(value)));
}

function normalizeSimuladoQuestionTarget(value?: number | null): number {
  if (typeof value !== "number" || !Number.isFinite(value)) return DEFAULT_SIMULADO_QUESTION_COUNT;
  return Math.max(MIN_SIMULADO_QUESTION_COUNT, Math.round(value));
}

function normalizeMonthlyQuestionLimit(value?: number | null): number {
  if (typeof value !== "number" || !Number.isFinite(value)) return DEFAULT_MONTHLY_QUESTION_LIMIT;
  return Math.min(MAX_MONTHLY_QUESTION_LIMIT, Math.max(1, Math.round(value)));
}

function formatCount(value: number): string {
  return new Intl.NumberFormat("pt-BR").format(Math.max(0, Math.floor(value)));
}

function formatQuestionCountLabel(value: number): string {
  const safeValue = Math.max(0, Math.floor(value));
  return `${formatCount(safeValue)} ${safeValue === 1 ? "questão" : "questões"}`;
}

function formatMinuteCountLabel(value: number): string {
  const safeValue = Math.max(0, Math.floor(value));
  return `${formatCount(safeValue)} ${safeValue === 1 ? "minuto" : "minutos"}`;
}

function getCurrentMonthKey(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
}

function hashString(value: string): number {
  let hash = 0;
  for (const char of value) {
    hash = (hash * 31 + char.charCodeAt(0)) % 2_147_483_647;
  }

  return hash || 1;
}

function getQuestionWindowPage(totalCount: number, perPage: number, userId?: string | null): number {
  const safeTotal = Math.max(0, Math.floor(totalCount));
  const safePerPage = Math.max(1, Math.floor(perPage));
  const totalPages = Math.max(1, Math.ceil(safeTotal / safePerPage));
  if (totalPages <= 1) return 1;

  const seed = hashString(`${userId || "guest"}:${getCurrentMonthKey()}`);
  return (seed % totalPages) + 1;
}

function getQuestionOrderSeed(): number {
  if (typeof window === "undefined") return 1;

  const stored = window.localStorage.getItem(QUESTION_ORDER_SEED_KEY);
  if (stored && Number.isFinite(Number(stored))) {
    return Number(stored);
  }

  const nextSeed = Math.floor(Math.random() * 2_147_483_647) || 1;
  window.localStorage.setItem(QUESTION_ORDER_SEED_KEY, String(nextSeed));
  return nextSeed;
}

function createSeededRandom(seed: number): () => number {
  let state = seed % 2_147_483_647;
  if (state <= 0) state += 2_147_483_646;

  return () => {
    state = (state * 16_807) % 2_147_483_647;
    return (state - 1) / 2_147_483_646;
  };
}

function shuffleQuestions<T>(items: T[], seed: number): T[] {
  const shuffled = [...items];
  const random = createSeededRandom(seed);

  for (let i = shuffled.length - 1; i > 0; i -= 1) {
    const j = Math.floor(random() * (i + 1));
    [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
  }

  return shuffled;
}

function buildSimuladoQuestions(items: Question[], seed: number): Question[] {
  if (items.length <= 1) return [...items];

  const byArea = new Map<string, Question[]>();
  for (const item of items) {
    const bucket = byArea.get(item.area) ?? [];
    bucket.push(item);
    byArea.set(item.area, bucket);
  }

  const areaOrder = shuffleQuestions(Array.from(byArea.keys()), seed + 17);
  const queues = new Map<string, Question[]>();

  for (const area of areaOrder) {
    queues.set(area, shuffleQuestions(byArea.get(area) ?? [], seed + hashString(area)));
  }

  const ordered: Question[] = [];
  let round = 0;

  while (ordered.length < items.length) {
    let addedThisRound = false;

    for (const area of areaOrder) {
      const queue = queues.get(area) || [];
      const question = queue[round];
      if (!question) continue;

      ordered.push(question);
      addedThisRound = true;
    }

    if (!addedThisRound) break;
    round += 1;
  }

  return ordered.length ? ordered : [...items];
}

function simulationStatusLabel(status: SimulationStatus): string {
  switch (status) {
    case "completed":
      return "Concluido";
    case "stopped":
      return "Parado";
    case "expired":
      return "Tempo esgotado";
  }
}

function simulationStatusClass(status: SimulationStatus): string {
  switch (status) {
    case "completed":
      return "green";
    case "stopped":
      return "orange";
    case "expired":
      return "red";
  }
}

function loadLocalSimulationHistory(userId?: string | null): SimulationRecord[] {
  if (typeof window === "undefined") return [];

  try {
    const raw = window.localStorage.getItem(getSimulationStorageKey(userId));
    if (!raw) return [];

    const parsed = JSON.parse(raw);
    return normalizeSimulationRecords(extractSimulationRecordsFromPayload(parsed));
  } catch {
    return [];
  }
}

function saveLocalSimulationHistory(userId: string | null | undefined, records: SimulationRecord[]): void {
  if (typeof window === "undefined") return;

  window.localStorage.setItem(getSimulationStorageKey(userId), JSON.stringify(normalizeSimulationRecords(records)));
}

type SimuladoBaseline = {
  area: string;
  currentIndex: number;
  answers: Partial<Record<number, AlternativeLetter>>;
  showResult: Partial<Record<number, boolean>>;
  questionOrderSeed: number;
};

function loadSimuladoBaseline(): SimuladoBaseline | null {
  if (typeof window === "undefined") return null;

  try {
    const raw = window.localStorage.getItem(SIMULADO_BASELINE_STORAGE_KEY);
    if (!raw) return null;

    const parsed = JSON.parse(raw) as Partial<SimuladoBaseline>;
    return {
      area: typeof parsed.area === "string" ? parsed.area : "Todas",
      currentIndex: typeof parsed.currentIndex === "number" ? Math.max(0, parsed.currentIndex) : 0,
      answers: parsed.answers || {},
      showResult: parsed.showResult || {},
      questionOrderSeed: typeof parsed.questionOrderSeed === "number" && Number.isFinite(parsed.questionOrderSeed)
        ? parsed.questionOrderSeed
        : 1,
    };
  } catch {
    return null;
  }
}

function saveSimuladoBaseline(value: SimuladoBaseline | null): void {
  if (typeof window === "undefined") return;

  if (!value) {
    window.localStorage.removeItem(SIMULADO_BASELINE_STORAGE_KEY);
    return;
  }

  window.localStorage.setItem(SIMULADO_BASELINE_STORAGE_KEY, JSON.stringify(value));
}

export default function RevalidaQuestoesMVP() {
  const [token, setToken] = useState<string | null>(null);
  const [user, setUser] = useState<DashboardUser | null>(null);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [isRegister, setIsRegister] = useState(false);
  const [authLoading, setAuthLoading] = useState(false);
  const [authError, setAuthError] = useState("");
  const [mode, setMode] = useState("dashboard");
  const [area, setArea] = useState("Todas");
  const [currentIndex, setCurrentIndex] = useState(0);
  const [answers, setAnswers] = useState<Partial<Record<number, AlternativeLetter>>>({});
  const [showResult, setShowResult] = useState<Partial<Record<number, boolean>>>({});
  const [simuladoActive, setSimuladoActive] = useState(false);
  const [simuladoElapsedSeconds, setSimuladoElapsedSeconds] = useState(0);
  const [simuladoDurationMinutes, setSimuladoDurationMinutes] = useState(DEFAULT_SIMULADO_DURATION_MINUTES);
  const [simuladoQuestionTarget, setSimuladoQuestionTarget] = useState(DEFAULT_SIMULADO_QUESTION_COUNT);
  const [simulationHistory, setSimulationHistory] = useState<SimulationRecord[]>([]);
  const [selectedSimulationId, setSelectedSimulationId] = useState<string | null>(null);
  const [simulationHistoryLoading, setSimulationHistoryLoading] = useState(false);
  const [simulationHistoryError, setSimulationHistoryError] = useState("");
  const [systemUsers, setSystemUsers] = useState<SystemUser[]>([]);
  const [systemUsersLoading, setSystemUsersLoading] = useState(false);
  const [systemUsersError, setSystemUsersError] = useState("");
  const [selectedSystemUserId, setSelectedSystemUserId] = useState<string | null>(null);
  const [systemUsersQuery, setSystemUsersQuery] = useState("");
  const [questionTextSize, setQuestionTextSize] = useState(DEFAULT_QUESTION_TEXT_SIZE);
  const [questionBankTotal, setQuestionBankTotal] = useState(0);
  const [questionBankTotalLoaded, setQuestionBankTotalLoaded] = useState(false);
  const [apiPendingCount, setApiPendingCount] = useState(0);
  const [questionsLoading, setQuestionsLoading] = useState(true);
  const [simuladoOrderSeed, setSimuladoOrderSeed] = useState(1);
  const simuladoFinalizeLockRef = useRef(false);
  const simuladoBaselineRef = useRef<{
    area: string;
    currentIndex: number;
    answers: Partial<Record<number, AlternativeLetter>>;
    showResult: Partial<Record<number, boolean>>;
    questionOrderSeed: number;
  } | null>(null);
  const [questions, setQuestions] = useState<Question[]>(INITIAL_QUESTIONS);
  const [adminForm, setAdminForm] = useState<AdminFormState>({
    area: "Clínica Médica",
    tema: "Novo tema",
    dificuldade: "Média",
    enunciado: "",
    A: "",
    B: "",
    C: "",
    D: "",
    E: "",
    gabarito: "A",
    comentario: "",
  });
  const isAdmin = Boolean(user?.is_admin);
  const monthlyQuestionLimit = normalizeMonthlyQuestionLimit(user?.monthly_question_limit);
  const visibleQuestionBankTotal = questionBankTotalLoaded ? questionBankTotal : questions.length;
  const simuladoDurationMinutesValue = normalizeSimuladoDurationMinutes(simuladoDurationMinutes);
  const simuladoDurationSeconds = simuladoDurationMinutesValue * 60;

  useEffect(() => subscribeApiRequestCount(setApiPendingCount), []);

  async function refreshQuestions(silent = false): Promise<void> {
    if (!silent) setQuestionsLoading(true);

    try {
      const totalCount = await loadQuestionBankTotal().catch(() => null);
      if (typeof totalCount === "number") {
        setQuestionBankTotal(totalCount);
        setQuestionBankTotalLoaded(true);
      }

      const accessibleQuestions = await loadAccessibleQuestions(
        monthlyQuestionLimit,
        typeof totalCount === "number" ? totalCount : (questionBankTotal > 0 ? questionBankTotal : null),
        user?.id
      );

      setQuestions(accessibleQuestions);

      if (typeof totalCount !== "number" && accessibleQuestions.length) {
        setQuestionBankTotal(accessibleQuestions.length);
      }
    } catch {
      if (!silent) {
        setQuestions(INITIAL_QUESTIONS);
        setQuestionBankTotal(INITIAL_QUESTIONS.length);
        setQuestionBankTotalLoaded(false);
      }
    } finally {
      if (!silent) setQuestionsLoading(false);
    }
  }

  // Load saved session on mount
  useEffect(() => {
    // Check for Google OAuth callback
    const urlParams = new URLSearchParams(window.location.search);
    const code = urlParams.get("code");
    const state = urlParams.get("state");
    const error = urlParams.get("error");
    const errorDescription = urlParams.get("error_description");
    
    if (code) {
      setAuthLoading(true);
      // In dev StrictMode, effects can run twice. Remove OAuth params immediately
      // so we never attempt exchanging the same one-time code twice.
      window.history.replaceState({}, document.title, window.location.pathname);

      const callbackQuery = new URLSearchParams({ code });
      if (state) callbackQuery.set("state", state);

      apiFetch(`/auth/google/callback?${callbackQuery.toString()}`)
        .then(async (res) => {
          if (!res.ok) throw new Error(await readApiError(res, "Falha no login com Google"));
          return res.json();
        })
        .then((data) => {
          setToken(data.access_token);
          setUser(data.user);
          setName(data.user.name);
          setEmail(data.user.email);
          setQuestionTextSize(normalizeQuestionTextSize(data.user.question_text_size));
          void refreshQuestions();
        })
        .catch(err => {
          setAuthError(err instanceof Error ? err.message : "Erro no login Google");
        })
        .finally(() => {
          setAuthLoading(false);
        });
      return;
    }
    
    if (error) {
      setAuthError(errorDescription || "Login Google cancelado");
      window.history.replaceState({}, document.title, window.location.pathname);
    }

    simuladoBaselineRef.current = loadSimuladoBaseline();

    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved) {
      try {
        const parsed = JSON.parse(saved);
        if (parsed.token && parsed.user) {
          setToken(parsed.token);
          setUser(parsed.user);
          setName(parsed.user.name || "");
          setEmail(parsed.user.email || "");
          setQuestionTextSize(normalizeQuestionTextSize(parsed.user.question_text_size));
        }
        setMode(parsed.mode || "dashboard");
        setArea(parsed.area || "Todas");
        setCurrentIndex(parsed.currentIndex || 0);
        setAnswers(parsed.answers || {});
        setShowResult(parsed.showResult || {});
        setSimuladoActive(Boolean(parsed.simuladoActive));
        setSimuladoElapsedSeconds(
          typeof parsed.simuladoElapsedSeconds === "number"
            ? Math.max(0, Math.floor(parsed.simuladoElapsedSeconds))
            : 0
        );
        setSimuladoDurationMinutes(
          normalizeSimuladoDurationMinutes(
            typeof parsed.simuladoDurationMinutes === "number" ? parsed.simuladoDurationMinutes : undefined
          )
        );
        setSimuladoQuestionTarget(
          normalizeSimuladoQuestionTarget(
            typeof parsed.simuladoQuestionTarget === "number" ? parsed.simuladoQuestionTarget : undefined
          )
        );
        setSimuladoOrderSeed(
          typeof parsed.simuladoOrderSeed === "number" && Number.isFinite(parsed.simuladoOrderSeed)
            ? parsed.simuladoOrderSeed
            : 1
        );
      } catch {
        localStorage.removeItem(STORAGE_KEY);
      }
    }

    void refreshQuestions();
  }, []);

  useEffect(() => {
    if (!token || !user) return;

    let cancelled = false;
    const refreshSilently = () => {
      if (cancelled) return;
      void refreshQuestions(true);
    };

    const timer = window.setInterval(refreshSilently, 15000);

    const handleFocus = () => refreshSilently();
    const handleVisibility = () => {
      if (document.visibilityState === "visible") refreshSilently();
    };

    window.addEventListener("focus", handleFocus);
    document.addEventListener("visibilitychange", handleVisibility);

    return () => {
      cancelled = true;
      window.clearInterval(timer);
      window.removeEventListener("focus", handleFocus);
      document.removeEventListener("visibilitychange", handleVisibility);
    };
  }, [token, user]);

  // Save state to localStorage
  useEffect(() => {
    localStorage.setItem(
      STORAGE_KEY,
        JSON.stringify({ 
          token, 
          user, 
          mode, 
          area, 
          currentIndex, 
          answers, 
          showResult,
          simuladoActive,
          simuladoElapsedSeconds,
          simuladoDurationMinutes: simuladoDurationMinutesValue,
          simuladoQuestionTarget,
          simuladoOrderSeed,
        })
    );
  }, [
    token,
    user,
    mode,
    area,
    currentIndex,
    answers,
    showResult,
    simuladoActive,
    simuladoElapsedSeconds,
    simuladoDurationMinutesValue,
    simuladoQuestionTarget,
    simuladoOrderSeed,
    questions,
  ]);

  useEffect(() => {
    if (!token || !user) {
      setSimulationHistory([]);
      setSelectedSimulationId(null);
      setSimulationHistoryError("");
      setSimulationHistoryLoading(false);
      setSystemUsers([]);
      setSelectedSystemUserId(null);
      setSystemUsersError("");
      setSystemUsersLoading(false);
      setSystemUsersQuery("");
      setQuestionTextSize(DEFAULT_QUESTION_TEXT_SIZE);
      return;
    }

    let cancelled = false;
    const localFallback = loadLocalSimulationHistory(user.id);
    setSimulationHistoryLoading(true);
    setSimulationHistoryError("");

    apiFetch("/simulations", {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then(async (res) => {
        if (!res.ok) throw new Error(await readApiError(res, "Falha ao carregar simulados"));
        return res.json();
      })
      .then((payload) => {
        if (cancelled) return;
        const merged = normalizeSimulationRecords([
          ...extractSimulationRecordsFromPayload(payload),
          ...localFallback,
        ]);
        setSimulationHistory(merged);
        setSelectedSimulationId((prev) => (prev && merged.some((item) => item.id === prev) ? prev : merged[0]?.id ?? null));
        saveLocalSimulationHistory(user.id, merged);
      })
      .catch((err) => {
        if (cancelled) return;
        setSimulationHistory(localFallback);
        setSelectedSimulationId((prev) => (prev && localFallback.some((item) => item.id === prev) ? prev : localFallback[0]?.id ?? null));
        setSimulationHistoryError(err instanceof Error ? err.message : "Falha ao carregar simulados");
      })
      .finally(() => {
        if (!cancelled) setSimulationHistoryLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [token, user]);

  useEffect(() => {
    if (!token) return;

    let cancelled = false;

    apiFetch("/auth/me", {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then(async (res) => {
        if (!res.ok) throw new Error(await readApiError(res, "Falha ao atualizar sessão"));
        return res.json();
      })
      .then((profile) => {
        if (cancelled || !profile) return;

        setUser(profile);
        if (typeof profile.name === "string") setName(profile.name);
        if (typeof profile.email === "string") setEmail(profile.email);
        setQuestionTextSize(normalizeQuestionTextSize(profile.question_text_size));
      })
      .catch(() => {
        // If the session refresh fails, keep the cached auth state.
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

  useEffect(() => {
    if (!user) {
      setQuestionTextSize(DEFAULT_QUESTION_TEXT_SIZE);
      return;
    }

    setQuestionTextSize(normalizeQuestionTextSize(user.question_text_size));
  }, [user?.id, user?.question_text_size]);

  useEffect(() => {
    if (!isAdmin && (mode === "admin" || mode === "usuarios")) {
      setMode("dashboard");
    }
  }, [isAdmin, mode]);

  useEffect(() => {
    if (mode !== "usuarios" || !token || !isAdmin) return;
    void loadSystemUsers();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [mode, token, isAdmin]);

  useEffect(() => {
    if (!simuladoActive) return;
    const startedAt = Date.now() - simuladoElapsedSeconds * 1000;
    const timer = window.setInterval(() => {
      setSimuladoElapsedSeconds(Math.max(0, Math.floor((Date.now() - startedAt) / 1000)));
    }, 1000);

    return () => window.clearInterval(timer);
  }, [simuladoActive]);

  useEffect(() => {
    if (!simuladoActive) return;
    if (simuladoElapsedSeconds < simuladoDurationSeconds) return;

    setSimuladoElapsedSeconds(simuladoDurationSeconds);
    void finalizeSimulado("expired");
    window.alert(`Tempo do simulado encerrado (${formatMinuteCountLabel(simuladoDurationMinutesValue)}).`);
  }, [simuladoActive, simuladoElapsedSeconds, simuladoDurationMinutesValue, simuladoDurationSeconds]);

  // Auth functions
  async function handleLogin(e: React.FormEvent) {
    e.preventDefault();
    setAuthLoading(true);
    setAuthError("");
    
    try {
      const res = await apiFetch("/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, password }),
      });
      
      if (!res.ok) {
        throw new Error(await readApiError(res, "Falha no login"));
      }
      
      const data = await res.json();
      setToken(data.access_token);
      setUser(data.user);
      setName(data.user.name);
      setEmail(data.user.email);
      setQuestionTextSize(normalizeQuestionTextSize(data.user.question_text_size));
    } catch (err) {
      setAuthError(err instanceof Error ? err.message : "Erro ao fazer login");
    } finally {
      setAuthLoading(false);
    }
  }

  async function handleRegister(e: React.FormEvent) {
    e.preventDefault();
    setAuthLoading(true);
    setAuthError("");
    
    try {
      const res = await apiFetch("/auth/register", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, name, password }),
      });
      
      if (!res.ok) {
        throw new Error(await readApiError(res, "Falha no cadastro"));
      }
      
      const data = await res.json();
      setToken(data.access_token);
      setUser(data.user);
      setName(data.user.name);
      setEmail(data.user.email);
      setQuestionTextSize(normalizeQuestionTextSize(data.user.question_text_size));
    } catch (err) {
      setAuthError(err instanceof Error ? err.message : "Erro ao criar conta");
    } finally {
      setAuthLoading(false);
    }
  }

  async function handleGoogleLogin() {
    try {
      const res = await apiFetch("/auth/google/url");
      if (!res.ok) {
        throw new Error(await readApiError(res, "Google OAuth não configurado"));
      }
      const data = await res.json();
      // Redirect to Google OAuth
      window.location.href = data.authorization_url;
    } catch (err) {
      setAuthError(err instanceof Error ? err.message : "Erro ao iniciar login Google");
    }
  }

  function handleLogout() {
    if (token) {
      apiFetch("/auth/logout", {
        method: "POST",
        headers: { Authorization: `Bearer ${token}` },
      }).catch(() => {
        // Keep local logout resilient even if API is offline.
      });
    }

    setToken(null);
    setUser(null);
    setName("");
    setEmail("");
    setPassword("");
    setMode("dashboard");
    setQuestionTextSize(DEFAULT_QUESTION_TEXT_SIZE);
    setSimuladoActive(false);
    setSimuladoElapsedSeconds(0);
    setSimuladoDurationMinutes(DEFAULT_SIMULADO_DURATION_MINUTES);
    setSimuladoQuestionTarget(DEFAULT_SIMULADO_QUESTION_COUNT);
    simuladoFinalizeLockRef.current = false;
    simuladoBaselineRef.current = null;
    saveSimuladoBaseline(null);
    setSimulationHistory([]);
    setSelectedSimulationId(null);
    setSimulationHistoryLoading(false);
    setSimulationHistoryError("");
    setSystemUsers([]);
    setSelectedSystemUserId(null);
    setSystemUsersLoading(false);
    setSystemUsersError("");
    setSystemUsersQuery("");
    localStorage.removeItem(STORAGE_KEY);
    localStorage.removeItem(QUESTION_ORDER_SEED_KEY);
  }

  const areas = useMemo(() => ["Todas", ...Array.from(new Set(questions.map((q) => q.area)))], [questions]);
  const filteredQuestions = useMemo(() => {
    return area === "Todas" ? questions : questions.filter((q) => q.area === area);
  }, [area, questions]);
  const simuladoQuestionLimit = useMemo(() => {
    if (!questions.length) return 0;
    return Math.min(questions.length, normalizeSimuladoQuestionTarget(simuladoQuestionTarget));
  }, [questions.length, simuladoQuestionTarget]);
  const simuladoQuestions = useMemo(
    () => buildSimuladoQuestions(questions, simuladoOrderSeed).slice(0, simuladoQuestionLimit),
    [questions, simuladoOrderSeed, simuladoQuestionLimit]
  );
  const activeQuestions = simuladoActive ? simuladoQuestions : filteredQuestions;
  const simuladoCanStart = !questionsLoading && simuladoQuestions.length > 0;
  const simuladoQuestionInputValue = questionsLoading
    ? simuladoQuestionTarget
    : (questions.length ? Math.min(simuladoQuestionTarget, questions.length) : simuladoQuestionTarget);

  const current = activeQuestions[currentIndex] || activeQuestions[0];
  const currentQuestionCount = activeQuestions.length;
  const answeredCount = Object.keys(answers).length;
  const correctCount = questions.filter((q) => answers[q.id] === q.gabarito).length;
  const activeAnsweredCount = activeQuestions.filter((q) => Boolean(answers[q.id])).length;
  const activeCorrectCount = activeQuestions.filter((q) => answers[q.id] === q.gabarito).length;
  const wrongQuestions = questions.filter((q) => showResult[q.id] && answers[q.id] && answers[q.id] !== q.gabarito);
  const progress = questions.length ? Math.round((answeredCount / questions.length) * 100) : 0;
  const accuracy = answeredCount ? Math.round((correctCount / answeredCount) * 100) : 0;
  const activeProgress = currentQuestionCount ? Math.round((activeAnsweredCount / currentQuestionCount) * 100) : 0;
  const respondedLabel = questionsLoading ? "Carregando..." : `${answeredCount}/${questions.length}`;
  const correctLabel = questionsLoading ? "Carregando..." : `${correctCount}`;
  const progressLabel = questionsLoading ? "Carregando..." : `${progress}%`;
  const simuladoElapsedLabel = useMemo(() => {
    const minutes = Math.floor(simuladoElapsedSeconds / 60).toString().padStart(2, "0");
    const seconds = (simuladoElapsedSeconds % 60).toString().padStart(2, "0");
    return `${minutes}:${seconds}`;
  }, [simuladoElapsedSeconds]);
  const simuladoDurationLabel = useMemo(() => formatSimulationDuration(simuladoDurationSeconds), [simuladoDurationSeconds]);
  const selectedSimulation = useMemo(
    () => simulationHistory.find((item) => item.id === selectedSimulationId) || simulationHistory[0] || null,
    [simulationHistory, selectedSimulationId]
  );
  const simulationStats = useMemo(() => {
    if (!simulationHistory.length) {
      return {
        total: 0,
        averageAccuracy: 0,
        bestAccuracy: 0,
        averageElapsed: 0,
        finalized: 0,
      };
    }

    const total = simulationHistory.length;
    const averageAccuracy = Math.round(simulationHistory.reduce((sum, item) => sum + item.accuracy, 0) / total);
    const bestAccuracy = Math.max(...simulationHistory.map((item) => item.accuracy));
    const averageElapsed = Math.round(simulationHistory.reduce((sum, item) => sum + item.elapsed_seconds, 0) / total);

    return {
      total,
      averageAccuracy,
      bestAccuracy,
      averageElapsed,
      finalized: total,
    };
  }, [simulationHistory]);

  const performanceByArea = areas.filter((item) => item !== "Todas").map((item) => {
    const qs = questions.filter((q) => q.area === item);
    const answered = qs.filter((q) => answers[q.id]);
    const correct = qs.filter((q) => answers[q.id] === q.gabarito);
    return {
      area: item,
      total: qs.length,
      answered: answered.length,
      correct: correct.length,
      accuracy: answered.length ? Math.round((correct.length / answered.length) * 100) : 0,
    };
  });

  const visibleNavItems = NAV_ITEMS;
  const page = visibleNavItems.find((item) => item.key === mode) || visibleNavItems[0];
  const selectedSystemUser = useMemo(
    () => systemUsers.find((item) => item.id === selectedSystemUserId) || systemUsers[0] || null,
    [selectedSystemUserId, systemUsers]
  );
  const filteredSystemUsers = useMemo(() => {
    const query = systemUsersQuery.trim().toLowerCase();
    if (!query) return systemUsers;

    return systemUsers.filter((item) => {
      const haystack = `${item.name} ${item.email}`.toLowerCase();
      return haystack.includes(query);
    });
  }, [systemUsers, systemUsersQuery]);
  const systemUserStats = useMemo(() => {
    const total = systemUsers.length;
    const active = systemUsers.filter((item) => item.is_active).length;
    const admins = systemUsers.filter((item) => item.is_admin).length;
    const withSimulations = systemUsers.filter((item) => item.simulations_count > 0).length;

    return { total, active, admins, withSimulations };
  }, [systemUsers]);

  async function loadSystemUsers() {
    if (!token || !isAdmin) return;

    setSystemUsersLoading(true);
    setSystemUsersError("");

    try {
      const res = await apiFetch("/users", {
        headers: { Authorization: `Bearer ${token}` },
      });

      if (!res.ok) {
        throw new Error(await readApiError(res, "Falha ao carregar usuários"));
      }

      const payload = await res.json();
      const normalized = normalizeSystemUsers(extractSystemUsersFromPayload(payload));
      setSystemUsers(normalized);
      setSelectedSystemUserId((prev) => (prev && normalized.some((item) => item.id === prev) ? prev : normalized[0]?.id ?? null));
    } catch (err) {
      setSystemUsers([]);
      setSelectedSystemUserId(null);
      setSystemUsersError(err instanceof Error ? err.message : "Falha ao carregar usuários");
    } finally {
      setSystemUsersLoading(false);
    }
  }

  const ranking = [...RANKING_MOCK, { nome: name || "Você", acertos: correctCount, questoes: Math.max(answeredCount, 1) }]
    .map((r) => ({ ...r, aproveitamento: Math.round((r.acertos / r.questoes) * 100) }))
    .sort((a, b) => b.aproveitamento - a.aproveitamento);

  function reset() {
    if (!window.confirm("Tem certeza? Isso limpará progresso e respostas.")) return;
    setAnswers({});
    setShowResult({});
    setSimuladoActive(false);
    setSimuladoElapsedSeconds(0);
    simuladoBaselineRef.current = null;
    saveSimuladoBaseline(null);
    setCurrentIndex(0);
    setMode("dashboard");
  }

  function changeArea(value: string) {
    if (simuladoActive) return;
    setArea(value);
    setCurrentIndex(0);
  }

  function choose(letter: AlternativeLetter) {
    if (!current || showResult[current.id]) return;
    setAnswers((prev) => ({ ...prev, [current.id]: letter }));
  }

  function confirmAnswer() {
    if (!current || !answers[current.id]) return;
    setShowResult((prev) => ({ ...prev, [current.id]: true }));
  }

  function nextQuestion() {
    setCurrentIndex((prev) => Math.min(prev + 1, Math.max(currentQuestionCount - 1, 0)));
  }

  function prevQuestion() {
    setCurrentIndex((prev) => Math.max(prev - 1, 0));
  }

  async function persistQuestionTextSize(nextSize: number) {
    if (!token || !user) return;

    try {
      const res = await apiFetch("/auth/me", {
        method: "PATCH",
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ question_text_size: nextSize }),
      });

      if (!res.ok) {
        throw new Error(await readApiError(res, "Falha ao salvar preferência"));
      }

      const profile = await res.json();
      setUser(profile);
      setQuestionTextSize(normalizeQuestionTextSize(profile.question_text_size));
    } catch {
      // Keep the UI responsive even if persistence fails temporarily.
    }
  }

  function changeQuestionTextSize(delta: number) {
    setQuestionTextSize((prev) => {
      const next = Math.min(MAX_QUESTION_TEXT_SIZE, Math.max(MIN_QUESTION_TEXT_SIZE, prev + delta));
      void persistQuestionTextSize(next);
      return next;
    });
  }

  function undoLastQuestion() {
    if (questions.length <= INITIAL_QUESTIONS.length) return;
    setQuestions((prev) => prev.slice(0, -1));
  }

  function changeSimuladoQuestionTarget(rawValue: string) {
    const parsed = Number(rawValue);
    const normalized = normalizeSimuladoQuestionTarget(Number.isFinite(parsed) ? parsed : DEFAULT_SIMULADO_QUESTION_COUNT);
    setSimuladoQuestionTarget(questions.length ? Math.min(normalized, questions.length) : normalized);
  }

  function changeSimuladoDurationMinutes(rawValue: string) {
    const parsed = Number(rawValue);
    setSimuladoDurationMinutes(
      normalizeSimuladoDurationMinutes(Number.isFinite(parsed) ? parsed : DEFAULT_SIMULADO_DURATION_MINUTES)
    );
  }

  function buildSimulationRecord(status: SimulationStatus): SimulationRecord {
    const elapsedSeconds = Math.min(simuladoElapsedSeconds, simuladoDurationSeconds);
    const answeredQuestions = simuladoQuestions.filter((q) => Boolean(answers[q.id])).length;
    const correctQuestions = simuladoQuestions.filter((q) => answers[q.id] === q.gabarito).length;
    const accuracyValue = answeredQuestions ? Math.round((correctQuestions / answeredQuestions) * 100) : 0;
    const now = new Date();

    return {
      id: `local-${now.getTime()}-${Math.random().toString(16).slice(2, 8)}`,
      user_id: user?.id,
      title: `Simulado · ${formatQuestionCountLabel(simuladoQuestions.length)} · ${formatMinuteCountLabel(simuladoDurationMinutesValue)}`,
      area: "Todas",
      status,
      started_at: new Date(now.getTime() - elapsedSeconds * 1000).toISOString(),
      ended_at: now.toISOString(),
      duration_seconds: simuladoDurationSeconds,
      elapsed_seconds: elapsedSeconds,
      total_questions: simuladoQuestions.length,
      answered_questions: answeredQuestions,
      correct_questions: correctQuestions,
      accuracy: accuracyValue,
    };
  }

  async function persistSimulationRecord(record: SimulationRecord): Promise<SimulationRecord> {
    if (!token || !user) {
      const localRecords = normalizeSimulationRecords([record, ...loadLocalSimulationHistory(user?.id)]);
      saveLocalSimulationHistory(user?.id, localRecords);
      setSimulationHistory(localRecords);
      setSelectedSimulationId(record.id);
      return record;
    }

    try {
      const res = await apiFetch("/simulations", {
        method: "POST",
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json",
        },
        body: JSON.stringify(record),
      });

      if (!res.ok) {
        throw new Error(await readApiError(res, "Falha ao salvar simulado"));
      }

      const payload = await res.json();
      const saved = extractSimulationRecordsFromPayload(payload)[0] ?? record;
      const merged = normalizeSimulationRecords([saved, ...loadLocalSimulationHistory(user.id)]);
      setSimulationHistory(merged);
      setSelectedSimulationId(saved.id);
      saveLocalSimulationHistory(user.id, merged);
      return saved;
    } catch {
      const localRecords = normalizeSimulationRecords([record, ...loadLocalSimulationHistory(user.id)]);
      saveLocalSimulationHistory(user.id, localRecords);
      setSimulationHistory(localRecords);
      setSelectedSimulationId(record.id);
      return record;
    }
  }

  async function finalizeSimulado(status: SimulationStatus) {
    if (simuladoFinalizeLockRef.current) return;
    simuladoFinalizeLockRef.current = true;

    const record = buildSimulationRecord(status);
    setSimuladoActive(false);
    setMode("simulado");
    await persistSimulationRecord(record);

    const baseline = simuladoBaselineRef.current;
    if (baseline) {
      setArea(baseline.area);
      setCurrentIndex(baseline.currentIndex);
      setAnswers(baseline.answers);
      setShowResult(baseline.showResult);
      setSimuladoOrderSeed(baseline.questionOrderSeed || 1);
    }
    simuladoBaselineRef.current = null;
    saveSimuladoBaseline(null);
  }

  function startSimulado() {
    if (simuladoActive || !simuladoCanStart) return;

    simuladoFinalizeLockRef.current = false;
    const nextSeed = Math.floor(Math.random() * 2_147_483_647) || 1;
    const baseline: SimuladoBaseline = {
      area,
      currentIndex,
      answers,
      showResult,
      questionOrderSeed: nextSeed,
    };
    simuladoBaselineRef.current = baseline;
    saveSimuladoBaseline(baseline);
    setSimuladoOrderSeed(nextSeed);
    setArea("Todas");
    setCurrentIndex(0);
    setAnswers({});
    setShowResult({});
    setSimuladoElapsedSeconds(0);
    setSimuladoActive(true);
    setMode("questoes");
  }

  function stopSimulado() {
    void finalizeSimulado("stopped");
  }

  async function addQuestion(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!adminForm.enunciado.trim()) return;

    const gabarito = (["A", "B", "C", "D", "E"].includes(adminForm.gabarito)
      ? adminForm.gabarito
      : "A") as AlternativeLetter;

    const payload: Omit<Question, "id"> = {
      area: adminForm.area,
      tema: adminForm.tema,
      dificuldade: adminForm.dificuldade,
      enunciado: adminForm.enunciado,
      alternativas: { A: adminForm.A, B: adminForm.B, C: adminForm.C, D: adminForm.D, E: adminForm.E },
      gabarito,
      comentario: adminForm.comentario,
    };

    try {
      const res = await apiFetch("/questions", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      if (!res.ok) throw new Error("Erro ao salvar");
      const saved = (await res.json()) as Question;
      setQuestions((prev) => [...prev, saved]);
    } catch {
      const localFallback: Question = { id: Date.now(), ...payload };
      setQuestions((prev) => [...prev, localFallback]);
      alert("API Laravel offline. Questão adicionada só localmente por enquanto.");
    }

    setAdminForm((prev) => ({ ...prev, enunciado: "", A: "", B: "", C: "", D: "", E: "", comentario: "" }));
  }

  if (!token || !user) {
    return (
      <>
        <AppStyles />
        {apiPendingCount > 0 && (
          <div className="global-loader" aria-live="polite" aria-busy="true">
            <div className="global-loader-track">
              <div className="global-loader-bar" />
            </div>
            <div className="global-loader-label">
              <span className="mini-spinner" />
              Carregando...
            </div>
          </div>
        )}
        <div className="login-page">
          <section className="login-hero">
            <div className="brand-row">
              <div className="brand-logo"><Icon>local_library</Icon></div>
              <div>
                <h1>Revalida</h1>
                <p>Questões & simulados</p>
              </div>
            </div>

            <div className="hero-copy">
              <h2>Estude medicina com dados, revisão e consistência.</h2>
              <p>Um banco de questões para Revalida e residência com comentários, caderno de erros, dashboard e simulados.</p>
            </div>

            <div className="hero-stats">
              <div className="hero-stat"><b>20</b><span>questões por dia</span></div>
              <div className="hero-stat"><b>5</b><span>grandes áreas</span></div>
              <div className="hero-stat"><b>100%</b><span>foco Revalida</span></div>
            </div>
          </section>

          <section className="login-panel">
            <div className="card login-card">
              <div className="card-content">
                <h3>{isRegister ? "Criar conta" : "Entrar"}</h3>
                <p className="subtitle">{isRegister ? "Crie sua conta para salvar seu progresso." : "Acesse seu painel de estudo e continue de onde parou."}</p>

                <form onSubmit={isRegister ? handleRegister : handleLogin}>
                  {isRegister && (
                    <div style={{ marginBottom: 18 }}>
                      <label className="field-label">Nome</label>
                      <input className="md-input" placeholder="Ex: Marco" value={name} onChange={(e) => setName(e.target.value)} required />
                    </div>
                  )}

                  <div style={{ marginBottom: 18 }}>
                    <label className="field-label">E-mail</label>
                    <input className="md-input" type="email" placeholder="voce@email.com" value={email} onChange={(e) => setEmail(e.target.value)} required />
                  </div>

                  <div style={{ marginBottom: 26 }}>
                    <label className="field-label">Senha</label>
                    <input className="md-input" type="password" placeholder="••••••••" value={password} onChange={(e) => setPassword(e.target.value)} required />
                  </div>

                  {authError && (
                    <div style={{ marginBottom: 18, padding: "12px 16px", borderRadius: 12, background: "#ffebee", color: "#c62828", fontSize: 14, fontWeight: 600 }}>
                      {authError}
                    </div>
                  )}

                  <button className="md-btn primary block" type="submit" disabled={authLoading}>
                    {authLoading ? "Aguarde..." : (isRegister ? "Criar conta" : "Entrar no painel")} <Icon>arrow_forward</Icon>
                  </button>
                </form>

                <div style={{ marginTop: 20, display: "flex", flexDirection: "column", gap: 12 }}>
                  <button className="md-btn outline block" onClick={handleGoogleLogin} type="button">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                      <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                      <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                      <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                      <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                    </svg>
                    Continuar com Google
                  </button>

                  <button 
                    className="md-btn" 
                    style={{ background: "transparent", color: "#607d8b", fontSize: 14, fontWeight: 700, height: 40 }} 
                    onClick={() => { setIsRegister(!isRegister); setAuthError(""); }}
                    type="button"
                  >
                    {isRegister ? "Já tem conta? Entrar" : "Não tem conta? Criar"}
                  </button>
                </div>
              </div>
            </div>
          </section>
        </div>
      </>
    );
  }

  return (
    <>
      <AppStyles />
      {apiPendingCount > 0 && (
        <div className="global-loader" aria-live="polite" aria-busy="true">
          <div className="global-loader-track">
            <div className="global-loader-bar" />
          </div>
          <div className="global-loader-label">
            <span className="mini-spinner" />
            Carregando...
          </div>
        </div>
      )}
      <div className="app-shell">
        <div className="layout">
          <aside className="sidebar">
            <div className="sidebar-brand">
              <div className="logo"><Icon>local_library</Icon></div>
              <div>
                <h2>Revalida</h2>
                <p>Questões & simulados</p>
              </div>
            </div>

            <nav className="nav-list">
              {visibleNavItems.map((item) => (
                <button key={item.key} className={`nav-btn ${mode === item.key ? "active" : ""}`} onClick={() => setMode(item.key)}>
                  <Icon>{item.icon}</Icon>
                  <span>{item.label}</span>
                </button>
              ))}
            </nav>

            <div className="sidebar-user">
              {user?.avatar_url ? (
                <img src={user.avatar_url} alt={user.name} style={{ width: 48, height: 48, borderRadius: "50%", marginBottom: 8 }} />
              ) : (
                <div style={{ width: 48, height: 48, borderRadius: "50%", background: "var(--primary)", color: "#fff", display: "grid", placeItems: "center", marginBottom: 8, fontSize: 20, fontWeight: 900 }}>
                  {user?.name?.charAt(0).toUpperCase() || "?"}
                </div>
              )}
              <b>{user?.name}</b>
              <span>{user?.email}</span>
              <div className="sidebar-actions">
                <button className="md-btn outline" onClick={reset}>Reset</button>
                <button className="md-btn outline" onClick={handleLogout}>Sair</button>
              </div>
            </div>
          </aside>

          <main className="content">
            <div className="mobile-nav">
              {visibleNavItems.map((item) => (
                <button key={item.key} className={`nav-btn ${mode === item.key ? "active" : ""}`} onClick={() => setMode(item.key)}>
                  <Icon>{item.icon}</Icon>
                  <span>{item.label}</span>
                </button>
              ))}
            </div>

            <header className="topbar">
              <div className="page-title">
                <h1>{page.label}</h1>
                <p>
                  {mode === "dashboard"
                    ? "Visão geral do seu estudo"
                    : mode === "questoes"
                      ? "Resolva, confira e revise"
                      : mode === "simulado"
                        ? "Histórico e dados dos seus simulados"
                        : mode === "usuarios"
                          ? "Lista de usuários do sistema"
                          : "Cadastro e manutenção do banco"}
                </p>
              </div>
              {simuladoActive ? (
                <div className="simulado-toolbar">
                  <span className="simulado-badge">
                    <Icon>timer</Icon>
                    Simulado ativo · {formatQuestionCountLabel(simuladoQuestions.length)} · {simuladoElapsedLabel} / {simuladoDurationLabel}
                  </span>
                  {mode !== "questoes" && (
                    <button className="md-btn outline" onClick={() => setMode("questoes")}>Voltar ao simulado</button>
                  )}
                  <button className="md-btn outline" onClick={stopSimulado}>Parar simulado</button>
                </div>
              ) : mode === "admin" ? (
                <div className="simulado-toolbar">
                  <button className="md-btn outline" onClick={() => setMode("usuarios")}>Listar usuários</button>
                  <button className="md-btn primary" onClick={loadSystemUsers}>Atualizar usuários</button>
                </div>
              ) : mode === "usuarios" ? (
                <button className="md-btn primary" onClick={() => void loadSystemUsers()}>Atualizar usuários</button>
              ) : mode !== "questoes" ? (
                <button className="md-btn primary" onClick={() => setMode("questoes")}>Continuar treino</button>
              ) : null}
            </header>

            {mode === "dashboard" && (
              <section>
                <div className="stat-grid">
                  <StatCard title="Respondidas" value={respondedLabel} icon="assignment_turned_in" color="indigo" helper="Total resolvido" />
                  <StatCard title="Acertos" value={correctLabel} icon="check_circle" color="green" helper="Questões corretas" />
                  <StatCard title="Aproveitamento" value={progressLabel} icon="show_chart" color="blue" helper="Média geral" />
                  <StatCard title="Banco total" value={questionsLoading ? "..." : formatCount(visibleQuestionBankTotal)} icon="menu_book" color="purple" helper="Questões cadastradas" />
                  <StatCard title="Revisar" value={wrongQuestions.length} icon="error_outline" color="orange" helper="Erros salvos" />
                </div>

                <div className="dashboard-grid">
                  <div className="card">
                    <div className="card-content">
                      <div className="section-title">
                        <div>
                          <h2>Desempenho por área</h2>
                          <p>Veja onde você está forte e onde precisa revisar.</p>
                        </div>
                        <span className="chip md-chip indigo white-text">{accuracy}% geral</span>
                      </div>
                      {performanceByArea.map((item) => <AreaBar key={item.area} item={item} />)}
                    </div>
                  </div>

                  <div className="side-summary">
                    <div className="card donut-card">
                      <div className="card-content">
                        <div className="section-title" style={{ display: "block", textAlign: "left" }}>
                          <h2>Progresso</h2>
                          <p>Conclusão do banco atual.</p>
                        </div>
                        <div className="donut" style={{ background: `conic-gradient(#3f51b5 ${progress * 3.6}deg, #e8eaf6 0deg)` }}>
                          <div className="donut-inner"><div><b>{progress}%</b><span>completo</span></div></div>
                        </div>
                        <button className="md-btn primary block" onClick={() => setMode("questoes")}>Resolver agora</button>
                      </div>
                    </div>

                    <div className="card">
                      <div className="card-content">
                        <div className="section-title" style={{ display: "block" }}>
                          <h2>Erros recentes</h2>
                          <p>Últimos pontos fracos.</p>
                        </div>
                        <RecentErrorsCarousel
                          items={wrongQuestions}
                          emptyMessage="Nenhum erro registrado ainda."
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </section>
            )}

            {mode === "questoes" && (
              <section>
                <div className="area-tabs">
                  {areas.map((item) => (
                    <button
                      key={item}
                      className={`area-tab ${area === item ? "active" : ""}`}
                      onClick={() => changeArea(item)}
                      disabled={simuladoActive}
                    >
                      {item}
                    </button>
                  ))}
                </div>

                <div className="question-layout">
                    <div className="card">
                      <div className="card-content">
                      {questionsLoading ? (
                        <div style={{ minHeight: 360, display: "grid", placeItems: "center", textAlign: "center" }}>
                          <div>
                            <div className="loading-block" style={{ marginBottom: 12 }}>
                              <span className="mini-spinner" />
                              Carregando perguntas...
                            </div>
                            <p style={{ margin: 0, color: "#607d8b" }}>
                              Estamos preparando seu banco de questões.
                            </p>
                          </div>
                        </div>
                      ) : current ? (
                        <>
                          <div className="question-meta">
                            <span className="chip md-chip indigo white-text">{current.area}</span>
                            <span className="chip md-chip indigo lighten-5 indigo-text text-darken-2">{current.tema}</span>
                            <span className="chip md-chip amber lighten-5 amber-text text-darken-3">{current.dificuldade}</span>
                            <span className="question-counter">
                              {questionsLoading ? "Carregando questões..." : `Questão ${currentIndex + 1} de ${currentQuestionCount}`}
                            </span>
                          </div>

                          <div className="question-text-toolbar">
                            <span>Tamanho da pergunta</span>
                            <div className="question-text-controls">
                              <button
                                type="button"
                                className="question-text-btn"
                                onClick={() => changeQuestionTextSize(-QUESTION_TEXT_SIZE_STEP)}
                                disabled={questionTextSize <= MIN_QUESTION_TEXT_SIZE}
                                aria-label="Diminuir tamanho da pergunta"
                              >
                                -
                              </button>
                              <div className="question-text-size">{questionTextSize}px</div>
                              <button
                                type="button"
                                className="question-text-btn"
                                onClick={() => changeQuestionTextSize(QUESTION_TEXT_SIZE_STEP)}
                                disabled={questionTextSize >= MAX_QUESTION_TEXT_SIZE}
                                aria-label="Aumentar tamanho da pergunta"
                              >
                                +
                              </button>
                            </div>
                          </div>

                          <p className="question-text" style={{ fontSize: `${questionTextSize}px` }}>{current.enunciado}</p>

                          <div className="answers">
                            {(Object.entries(current.alternativas) as [AlternativeLetter, string][]).map(([letter, text]) => {
                              const selected = answers[current.id] === letter;
                              const revealed = showResult[current.id];
                              const isCorrect = current.gabarito === letter;
                              const isWrongSelected = revealed && selected && !isCorrect;
                              return (
                                <button
                                  key={letter}
                                  onClick={() => choose(letter)}
                                  className={`answer-btn ${selected ? "selected" : ""} ${revealed && isCorrect ? "correct" : ""} ${isWrongSelected ? "wrong" : ""}`}
                                >
                                  <span className="answer-letter">{letter}</span>
                                  <span className="answer-text">{text}</span>
                                </button>
                              );
                            })}
                          </div>

                          <div className="question-actions">
                            <button className="md-btn outline" onClick={prevQuestion} disabled={currentIndex === 0}>Anterior</button>
                            <div className="right">
                              <button className="md-btn primary" onClick={confirmAnswer} disabled={!answers[current.id] || showResult[current.id]}>Responder</button>
                              <button className="md-btn outline" onClick={nextQuestion} disabled={currentIndex === currentQuestionCount - 1}>Próxima</button>
                            </div>
                          </div>

                          {showResult[current.id] && (
                            <div className="result-box">
                              <h4>
                                <Icon>{answers[current.id] === current.gabarito ? "check_circle" : "cancel"}</Icon>
                                {answers[current.id] === current.gabarito ? "Correto" : "Errado"} · Gabarito {current.gabarito}
                              </h4>
                              <p>{current.comentario}</p>
                            </div>
                          )}
                        </>
                      ) : <p>Nenhuma questão encontrada.</p>}
                      </div>
                    </div>

                  <div className="side-summary">
                    <div className="card">
                      <div className="card-content">
                        <div className="section-title" style={{ display: "block" }}>
                          <h2>{simuladoActive ? "Simulado" : "Sessão"}</h2>
                          <p>{simuladoActive ? "Resumo rápido do simulado atual." : "Resumo rápido do estudo."}</p>
                        </div>
                        <div className="mini-grid">
                          <div className="mini-stat">
                            <span>{simuladoActive ? "Respondidas" : "Acertos"}</span>
                            <b>{simuladoActive ? activeAnsweredCount : correctCount}</b>
                          </div>
                          <div className="mini-stat">
                            <span>{simuladoActive ? "Tempo" : "Erros"}</span>
                            <b>{simuladoActive ? simuladoElapsedLabel : wrongQuestions.length}</b>
                          </div>
                        </div>
                        <div style={{ marginTop: 18 }}>
                          <AreaBar
                            item={{
                              area: simuladoActive ? "Progresso do simulado" : "Progresso geral",
                              total: simuladoActive ? currentQuestionCount : questions.length,
                              answered: simuladoActive ? activeAnsweredCount : answeredCount,
                              correct: simuladoActive ? activeCorrectCount : correctCount,
                              accuracy: simuladoActive ? activeProgress : progress,
                            }}
                          />
                        </div>
                      </div>
                    </div>

                    <div className="card">
                      <div className="card-content">
                        <div className="section-title" style={{ display: "block" }}>
                          <h2>Revisar</h2>
                          <p>Erros recentes.</p>
                        </div>
                        <RecentErrorsCarousel
                          items={wrongQuestions}
                          emptyMessage="Nenhum erro ainda."
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </section>
            )}

            {mode === "simulado" && (
              <section>
                <div className="card" style={{ marginBottom: 22 }}>
                  <div className="card-content">
                    <div className="section-title" style={{ display: "block" }}>
                      <h2>{simuladoActive ? "Simulado em andamento" : "Configurar simulado"}</h2>
                      <p>
                        {simuladoActive
                          ? "A configuração fica travada até você encerrar o simulado atual."
                          : "Escolha quantas questões quer resolver dentro do limite disponível e por quanto tempo."}
                      </p>
                    </div>

                    <div className="simulado-config-grid">
                      <label className="simulado-config-field">
                        <span>Quantidade de questões</span>
                        <input
                          type="number"
                          min={MIN_SIMULADO_QUESTION_COUNT}
                          max={Math.max(MIN_SIMULADO_QUESTION_COUNT, questions.length)}
                          step={1}
                          value={simuladoQuestionInputValue}
                          onChange={(event) => changeSimuladoQuestionTarget(event.target.value)}
                          disabled={questionsLoading || simuladoActive || questions.length === 0}
                        />
                        <small>
                          {questionsLoading
                            ? "Carregando o limite disponível..."
                            : questions.length
                              ? `Máximo no seu banco atual: ${formatQuestionCountLabel(questions.length)}.`
                              : "Nenhuma questão disponível no momento."}
                        </small>
                      </label>

                      <label className="simulado-config-field">
                        <span>Tempo</span>
                        <input
                          type="number"
                          min={MIN_SIMULADO_DURATION_MINUTES}
                          max={MAX_SIMULADO_DURATION_MINUTES}
                          step={1}
                          value={simuladoDurationMinutesValue}
                          onChange={(event) => changeSimuladoDurationMinutes(event.target.value)}
                          disabled={simuladoActive}
                        />
                        <small>Padrão: {formatMinuteCountLabel(DEFAULT_SIMULADO_DURATION_MINUTES)}.</small>
                      </label>
                    </div>

                    <div className="simulado-config-summary">
                      <div className="simulado-config-pill">
                        <Icon>quiz</Icon>
                        {formatQuestionCountLabel(simuladoQuestions.length)}
                      </div>
                      <div className="simulado-config-pill">
                        <Icon>schedule</Icon>
                        {formatMinuteCountLabel(simuladoDurationMinutesValue)}
                      </div>
                      {simuladoActive && (
                        <div className="simulado-config-pill active">
                          <Icon>play_circle</Icon>
                          {simuladoElapsedLabel} corridos
                        </div>
                      )}
                    </div>

                    <div className="simulado-config-actions">
                      {simuladoActive ? (
                        <>
                          <button className="md-btn primary" onClick={() => setMode("questoes")}>Voltar ao simulado</button>
                          <button className="md-btn outline" onClick={stopSimulado}>Parar simulado</button>
                        </>
                      ) : (
                        <button className="md-btn primary" onClick={startSimulado} disabled={!simuladoCanStart}>
                          Começar simulado
                        </button>
                      )}
                    </div>
                  </div>
                </div>

                <div className="simulado-metrics">
                  <div className="simulado-metric">
                    <span>Simulados</span>
                    <b>{simulationStats.total}</b>
                    <p>Total salvo no histórico</p>
                  </div>
                  <div className="simulado-metric">
                    <span>Aproveitamento médio</span>
                    <b>{simulationStats.averageAccuracy}%</b>
                    <p>Média geral dos simulados</p>
                  </div>
                  <div className="simulado-metric">
                    <span>Melhor nota</span>
                    <b>{simulationStats.bestAccuracy}%</b>
                    <p>Maior resultado registrado</p>
                  </div>
                  <div className="simulado-metric">
                    <span>Tempo médio</span>
                    <b>{formatSimulationDuration(simulationStats.averageElapsed)}</b>
                    <p>{simulationStats.finalized} finalizados</p>
                  </div>
                </div>

                <div className="simulado-history-grid">
                  <div className="card">
                    <div className="card-content">
                      <div className="section-title" style={{ display: "block" }}>
                        <h2>Histórico de simulados</h2>
                        <p>Selecione um simulado para abrir os dados.</p>
                      </div>

                      {simulationHistoryLoading && (
                        <div className="loading-block" style={{ marginBottom: 12 }}>
                          <span className="mini-spinner" />
                          Carregando simulados...
                        </div>
                      )}
                      {simulationHistoryError && !simulationHistory.length && (
                        <p style={{ color: "#ef6c00" }}>{simulationHistoryError}</p>
                      )}

                      {!simulationHistoryLoading && !simulationHistory.length && (
                        <div style={{ padding: 18, borderRadius: 18, border: "1px dashed var(--border)", background: "#fafbff" }}>
                          <h3 style={{ margin: "0 0 8px", fontSize: 18, fontWeight: 900 }}>Nenhum simulado salvo ainda</h3>
                          <p style={{ margin: 0, color: "#607d8b", lineHeight: 1.6 }}>
                            Comece um simulado para registrar duração, acertos e aproveitamento.
                          </p>
                        </div>
                      )}

                      <div className="simulado-history-list" style={{ marginTop: simulationHistory.length ? 0 : 18 }}>
                        {simulationHistory.map((simulation) => (
                          <button
                            key={simulation.id}
                            className={`simulado-history-item ${selectedSimulation?.id === simulation.id ? "active" : ""}`}
                            onClick={() => setSelectedSimulationId(simulation.id)}
                            type="button"
                          >
                            <div className="simulado-history-main">
                              <h3>{simulation.title}</h3>
                              <p>
                                {formatSimulationDate(simulation.started_at)} · {simulation.area} · {simulation.answered_questions}/{simulation.total_questions} respondidas
                              </p>
                            </div>
                            <div style={{ display: "grid", justifyItems: "end", gap: 8 }}>
                              <span className={`status-pill ${simulationStatusClass(simulation.status)}`}>
                                {simulationStatusLabel(simulation.status)}
                              </span>
                              <strong style={{ fontSize: 28, fontWeight: 900, letterSpacing: "-0.05em", color: "#1f2937" }}>
                                {simulation.accuracy}%
                              </strong>
                            </div>
                          </button>
                        ))}
                      </div>
                    </div>
                  </div>

                  <div className="simulado-history-side">
                    <div className="card">
                      <div className="card-content">
                        <div className="section-title" style={{ display: "block" }}>
                          <h2>Dados do simulado</h2>
                          <p>{selectedSimulation ? "Resumo do simulado selecionado." : "Escolha um item da lista ao lado."}</p>
                        </div>

                        {selectedSimulation ? (
                          <div className="simulado-detail">
                            <div className="simulado-detail-grid">
                              <div className="simulado-detail-card">
                                <span>Status</span>
                                <b>{simulationStatusLabel(selectedSimulation.status)}</b>
                                <p>{formatSimulationDate(selectedSimulation.ended_at)}</p>
                              </div>
                              <div className="simulado-detail-card">
                                <span>Aproveitamento</span>
                                <b>{selectedSimulation.accuracy}%</b>
                                <p>{selectedSimulation.correct_questions}/{selectedSimulation.answered_questions} acertos</p>
                              </div>
                              <div className="simulado-detail-card">
                                <span>Tempo usado</span>
                                <b>{formatSimulationDuration(selectedSimulation.elapsed_seconds)}</b>
                                <p>Limite {formatSimulationDuration(selectedSimulation.duration_seconds)}</p>
                              </div>
                              <div className="simulado-detail-card">
                                <span>Questões</span>
                                <b>{selectedSimulation.answered_questions}/{selectedSimulation.total_questions}</b>
                                <p>Respondidas no simulado</p>
                              </div>
                            </div>

                            <div className="simulado-detail-card">
                              <span>Período</span>
                              <b>{formatSimulationDate(selectedSimulation.started_at)}</b>
                              <p>Início do simulado · {formatSimulationDate(selectedSimulation.ended_at)}</p>
                            </div>

                            <div className="simulado-detail-card">
                              <span>Resumo</span>
                              <b>{selectedSimulation.area}</b>
                              <p>{selectedSimulation.title}</p>
                            </div>

                            {simuladoActive ? (
                              <button className="md-btn primary block" onClick={() => setMode("questoes")}>
                                Voltar ao simulado
                              </button>
                            ) : (
                              <button className="md-btn primary block" onClick={startSimulado}>
                                Começar novo simulado
                              </button>
                            )}
                          </div>
                        ) : (
                          simuladoActive ? (
                            <button className="md-btn primary block" onClick={() => setMode("questoes")}>
                              Voltar ao simulado
                            </button>
                          ) : (
                            <button className="md-btn primary block" onClick={startSimulado}>
                              Começar simulado
                            </button>
                          )
                        )}
                      </div>
                    </div>
                  </div>
                </div>
              </section>
            )}

            {mode === "ranking" && (
              <section>
                <div className="card"><div className="card-content">
                  {ranking.map((r, index) => (
                    <div className="error-item" key={r.nome} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 12 }}>
                      <div style={{ display: "flex", alignItems: "center", gap: 14 }}>
                        <span className="answer-letter">{index + 1}</span>
                        <div><b>{r.nome}</b><br /><span>{r.acertos}/{r.questoes} acertos</span></div>
                      </div>
                      <span className="chip md-chip indigo white-text">{r.aproveitamento}%</span>
                    </div>
                  ))}
                </div></div>
              </section>
            )}

            {mode === "usuarios" && (
              <section>
                <div className="usuarios-metrics">
                  <div className="simulado-metric">
                    <span>Total de usuários</span>
                    <b>{systemUserStats.total}</b>
                    <p>Contas cadastradas</p>
                  </div>
                  <div className="simulado-metric">
                    <span>Ativos</span>
                    <b>{systemUserStats.active}</b>
                    <p>Com acesso liberado</p>
                  </div>
                  <div className="simulado-metric">
                    <span>Administradores</span>
                    <b>{systemUserStats.admins}</b>
                    <p>Com acesso ao painel</p>
                  </div>
                  <div className="simulado-metric">
                    <span>Com simulados</span>
                    <b>{systemUserStats.withSimulations}</b>
                    <p>Usuários com atividade</p>
                  </div>
                </div>

                <div className="usuarios-grid">
                  <div className="card">
                    <div className="card-content">
                      <div className="section-title">
                        <div>
                          <h2>Usuários do sistema</h2>
                          <p>Filtre, selecione e veja os dados de cada conta.</p>
                        </div>
                        <div className="usuarios-actions">
                          <input
                            className="md-input usuarios-search"
                            placeholder="Buscar por nome ou email"
                            value={systemUsersQuery}
                            onChange={(e) => setSystemUsersQuery(e.target.value)}
                          />
                          <button className="md-btn outline" onClick={() => void loadSystemUsers()}>Atualizar lista</button>
                        </div>
                      </div>

                      {systemUsersLoading && (
                        <div className="loading-block" style={{ marginBottom: 12 }}>
                          <span className="mini-spinner" />
                          Carregando usuários...
                        </div>
                      )}
                      {systemUsersError && !systemUsers.length && (
                        <p style={{ color: "#ef6c00" }}>{systemUsersError}</p>
                      )}

                      {!systemUsersLoading && !filteredSystemUsers.length && (
                        <div style={{ padding: 18, borderRadius: 18, border: "1px dashed var(--border)", background: "#fafbff" }}>
                          <h3 style={{ margin: "0 0 8px", fontSize: 18, fontWeight: 900 }}>
                            Nenhum usuário encontrado
                          </h3>
                          <p style={{ margin: 0, color: "#607d8b", lineHeight: 1.6 }}>
                            Tente limpar o filtro ou recarregar a lista.
                          </p>
                        </div>
                      )}

                      <div className="usuarios-list" style={{ marginTop: filteredSystemUsers.length ? 0 : 18 }}>
                        {filteredSystemUsers.map((item) => (
                          <button
                            key={item.id}
                            className={`usuario-item ${selectedSystemUser?.id === item.id ? "active" : ""}`}
                            onClick={() => setSelectedSystemUserId(item.id)}
                            type="button"
                          >
                            <div className="usuario-item-main">
                              {item.avatar_url ? (
                                <img src={item.avatar_url} alt={item.name} className="usuario-avatar" />
                              ) : (
                                <div className="usuario-avatar fallback">
                                  {item.name?.charAt(0).toUpperCase() || "?"}
                                </div>
                              )}
                              <div className="usuario-item-copy">
                                <h3>{item.name}</h3>
                                <p>{item.email}</p>
                              </div>
                            </div>

                            <div className="usuario-item-side">
                              <span className={`status-pill ${item.is_active ? "green" : "red"}`}>
                                {item.is_active ? "Ativo" : "Inativo"}
                              </span>
                              <span className={`status-pill ${item.is_admin ? "orange" : "green"}`}>
                                {item.is_admin ? "Admin" : "Usuário"}
                              </span>
                              <span className="status-pill blue">
                                {formatCount(item.monthly_question_limit)} / mês
                              </span>
                              <strong>{item.simulations_count}</strong>
                            </div>
                          </button>
                        ))}
                      </div>
                    </div>
                  </div>

                  <div className="usuarios-side">
                    <div className="card">
                      <div className="card-content">
                        <div className="section-title" style={{ display: "block" }}>
                          <h2>Dados do usuário</h2>
                          <p>{selectedSystemUser ? "Resumo da conta selecionada." : "Escolha um usuário na lista ao lado."}</p>
                        </div>

                        {selectedSystemUser ? (
                          <div className="usuario-detail">
                            <div className="usuario-profile">
                              {selectedSystemUser.avatar_url ? (
                                <img src={selectedSystemUser.avatar_url} alt={selectedSystemUser.name} className="usuario-profile-avatar" />
                              ) : (
                                <div className="usuario-profile-avatar fallback">
                                  {selectedSystemUser.name?.charAt(0).toUpperCase() || "?"}
                                </div>
                              )}
                              <div>
                                <h3>{selectedSystemUser.name}</h3>
                                <p>{selectedSystemUser.email}</p>
                              </div>
                            </div>

                            <div className="usuario-badges">
                              <span className={`status-pill ${selectedSystemUser.is_active ? "green" : "red"}`}>
                                {selectedSystemUser.is_active ? "Conta ativa" : "Conta inativa"}
                              </span>
                              <span className={`status-pill ${selectedSystemUser.is_admin ? "orange" : "green"}`}>
                                {selectedSystemUser.is_admin ? "Perfil admin" : "Perfil usuário"}
                              </span>
                              <span className={`status-pill ${selectedSystemUser.email_verified_at ? "green" : "orange"}`}>
                                {selectedSystemUser.email_verified_at ? "Email verificado" : "Email não verificado"}
                              </span>
                              <span className="status-pill blue">
                                {formatCount(selectedSystemUser.monthly_question_limit)} questões/mês
                              </span>
                            </div>

                            <div className="usuario-detail-grid">
                              <div className="simulado-detail-card">
                                <span>Simulados</span>
                                <b>{selectedSystemUser.simulations_count}</b>
                                <p>Registros vinculados à conta</p>
                              </div>
                              <div className="simulado-detail-card">
                                <span>Cadastro</span>
                                <b>{formatUserDate(selectedSystemUser.created_at)}</b>
                                <p>Data de criação da conta</p>
                              </div>
                              <div className="simulado-detail-card">
                                <span>Atualização</span>
                                <b>{formatUserDate(selectedSystemUser.updated_at)}</b>
                                <p>Última mudança no perfil</p>
                              </div>
                              <div className="simulado-detail-card">
                                <span>Verificação</span>
                                <b>{formatUserDate(selectedSystemUser.email_verified_at)}</b>
                                <p>Status da confirmação do email</p>
                              </div>
                            </div>

                            <button className="md-btn primary block" onClick={() => void loadSystemUsers()}>
                              Atualizar lista
                            </button>
                          </div>
                        ) : (
                          <div style={{ padding: 18, borderRadius: 18, border: "1px dashed var(--border)", background: "#fafbff" }}>
                            <h3 style={{ margin: "0 0 8px", fontSize: 18, fontWeight: 900 }}>
                              Nenhum usuário selecionado
                            </h3>
                            <p style={{ margin: 0, color: "#607d8b", lineHeight: 1.6 }}>
                              Recarregue a lista para escolher uma conta.
                            </p>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
              </section>
            )}

            {mode === "admin" && (
              <section>
                <div className="card"><div className="card-content">
                  <div className="section-title">
                    <div><h2>Painel admin</h2><p>Cadastro local para prototipar o banco.</p></div>
                    <div className="usuarios-actions">
                      <button className="md-btn outline" onClick={() => setMode("usuarios")}>Listar usuários</button>
                      <button className="md-btn outline" onClick={undoLastQuestion}>Undo última</button>
                    </div>
                  </div>
                  <form className="admin-form" onSubmit={addQuestion}>
                    <input className="md-input" placeholder="Área" value={adminForm.area} onChange={(e) => setAdminForm({ ...adminForm, area: e.target.value })} />
                    <input className="md-input" placeholder="Tema" value={adminForm.tema} onChange={(e) => setAdminForm({ ...adminForm, tema: e.target.value })} />
                    <input className="md-input" placeholder="Dificuldade" value={adminForm.dificuldade} onChange={(e) => setAdminForm({ ...adminForm, dificuldade: e.target.value })} />
                    <input className="md-input" placeholder="Gabarito" value={adminForm.gabarito} onChange={(e) => setAdminForm({ ...adminForm, gabarito: e.target.value.toUpperCase() })} />
                    <textarea className="md-input span-2" placeholder="Enunciado" value={adminForm.enunciado} onChange={(e) => setAdminForm({ ...adminForm, enunciado: e.target.value })} />
                    {(["A", "B", "C", "D", "E"] as AlternativeLetter[]).map((l) => (
                      <input
                        key={l}
                        className="md-input"
                        placeholder={`Alternativa ${l}`}
                        value={adminForm[l]}
                        onChange={(e) => setAdminForm({ ...adminForm, [l]: e.target.value })}
                      />
                    ))}
                    <textarea className="md-input span-2" placeholder="Comentário" value={adminForm.comentario} onChange={(e) => setAdminForm({ ...adminForm, comentario: e.target.value })} />
                    <button className="md-btn primary span-2" type="submit">Adicionar questão</button>
                  </form>
                </div></div>
              </section>
            )}

            {mode === "planos" && (
              <section className="simple-grid">
                {[
                  { name: "Grátis", price: 0, limit: 1000, description: "Para testar a plataforma." },
                  {
                    name: "Pro",
                    price: 29,
                    limit: 5000,
                    description: questionBankTotalLoaded
                      ? `${formatCount(questionBankTotal)} questões no banco · até ${formatCount(5000)} por mês.`
                      : "Banco completo · até 5.000 questões por mês.",
                  },
                  { name: "Mentoria", price: 99, limit: 10000, description: "Pro + acompanhamento individual." },
                ].map((plan) => (
                  <div className={`card plan-card ${plan.limit === monthlyQuestionLimit ? "featured" : ""}`} key={plan.name}>
                    <div className="card-content">
                      <h2 style={{ fontWeight: 900 }}>{plan.name}</h2>
                      <h3 style={{ fontWeight: 900 }}>{`R$${plan.price}`}<small style={{ fontSize: 15, color: "#90a4ae" }}>/mês</small></h3>
                      <p style={{ color: "#78909c", minHeight: 52 }}>{plan.description}</p>
                      <p style={{ marginTop: -2, color: "#607d8b", fontSize: 13, fontWeight: 700 }}>
                        Limite mensal: {formatCount(plan.limit)} questões
                      </p>
                      <button className={`md-btn ${plan.limit === monthlyQuestionLimit ? "primary" : "outline"} block`}>
                        {plan.limit === monthlyQuestionLimit ? "Plano atual" : "Escolher"}
                      </button>
                    </div>
                  </div>
                ))}
              </section>
            )}
          </main>
        </div>
      </div>
    </>
  );
}
