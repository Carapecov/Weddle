"""Servidor MCP local para o BlackBoard do Weddell Pay (SDK MCP 1.x)."""
from __future__ import annotations
import json
import os
import re
import sqlite3
from datetime import datetime, timezone
from pathlib import Path
from mcp.server.fastmcp import FastMCP

ROOT = Path(os.environ.get('BLACKBOARD_DIR', str(Path(__file__).resolve().parent / 'BlackBoard'))).resolve()
ROOT.mkdir(parents=True, exist_ok=True)
(ROOT / 'tasks').mkdir(exist_ok=True)
DB = ROOT / 'events.sqlite3'
mcp = FastMCP('Weddell Pay BlackBoard')
AGENTS = {'arquitetura_api', 'frontend_ux', 'seguranca', 'qualidade_testes'}
STATES = {'planejada', 'em_andamento', 'bloqueada', 'em_revisao', 'validada', 'concluida'}


def now():
    return datetime.now(timezone.utc).isoformat(timespec='seconds')


def connect():
    conn = sqlite3.connect(DB, timeout=20)
    conn.row_factory = sqlite3.Row
    conn.execute('PRAGMA busy_timeout=20000')
    conn.execute('PRAGMA journal_mode=WAL')
    conn.execute('CREATE TABLE IF NOT EXISTS tasks (id TEXT PRIMARY KEY, title TEXT NOT NULL, owner TEXT NOT NULL, state TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)')
    conn.execute('CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id TEXT NOT NULL, at TEXT NOT NULL, agent TEXT NOT NULL, kind TEXT NOT NULL, description TEXT NOT NULL, files TEXT NOT NULL, evidence TEXT NOT NULL, base_version INTEGER, FOREIGN KEY(task_id) REFERENCES tasks(id))')
    return conn


def check_agent(value):
    if value not in AGENTS:
        raise ValueError(f'Agente inválido: {value}. Permitidos: {sorted(AGENTS)}')


def check_id(value):
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_-]{2,63}', value):
        raise ValueError('task_id deve conter 3-64 caracteres: letras, números, _ ou -')


def append_event(conn, task_id, agent, kind, description, files='', evidence='', base_version=None):
    conn.execute('INSERT INTO events(task_id,at,agent,kind,description,files,evidence,base_version) VALUES (?,?,?,?,?,?,?,?)', (task_id, now(), agent, kind, description, files, evidence, base_version))


def snapshot(conn, task_id):
    task = conn.execute('SELECT * FROM tasks WHERE id=?', (task_id,)).fetchone()
    if task is None:
        raise ValueError('Tarefa não encontrada')
    events = conn.execute('SELECT * FROM events WHERE task_id=? ORDER BY id', (task_id,)).fetchall()
    lines = [f'# {task["id"]} — {task["title"]}', '', f'- Responsável: `{task["owner"]}`', f'- Estado: `{task["state"]}`', f'- Versão: `{task["version"]}`', f'- Atualizado: `{task["updated_at"]}`', '', '## Histórico imutável no banco de eventos', '']
    for event in events:
        lines += [f'### Evento {event["id"]} — {event["kind"]}', f'- Quando: {event["at"]}', f'- Agente: `{event["agent"]}`', f'- Alteração: {event["description"]}', f'- Arquivos: {event["files"] or "(nenhum informado)"}', f'- Evidências: {event["evidence"] or "(não anexadas)"}', '']
    target = ROOT / 'tasks' / f'{task_id}.md'
    temp = target.with_suffix('.md.tmp')
    temp.write_text('\n'.join(lines), encoding='utf-8')
    os.replace(temp, target)


@mcp.tool()
def board_create_task(task_id: str, title: str, agent: str, description: str) -> dict:
    """Cria tarefa com histórico. Use um ID exclusivo, como PAY-001."""
    check_id(task_id); check_agent(agent)
    if not title.strip() or not description.strip():
        raise ValueError('Título e descrição obrigatórios')
    with connect() as conn:
        conn.execute('INSERT INTO tasks(id,title,owner,state,version,created_at,updated_at) VALUES (?,?,?,?,?,?,?)', (task_id, title, agent, 'planejada', 1, now(), now()))
        append_event(conn, task_id, agent, 'criacao', description)
        conn.commit(); snapshot(conn, task_id)
    return {'task_id':task_id, 'state':'planejada', 'version':1}


@mcp.tool()
def board_record_change(task_id: str, agent: str, change: str, reason: str, files_changed: str, test_evidence: str = '', expected_version: int = 0) -> dict:
    """Registra mudança efetuada, justificativa, arquivos e testes. expected_version evita sobrescrita concorrente."""
    check_id(task_id); check_agent(agent)
    if not change.strip() or not reason.strip() or not files_changed.strip():
        raise ValueError('Mudança, motivo e arquivos são obrigatórios')
    with connect() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row = conn.execute('SELECT version,state FROM tasks WHERE id=?', (task_id,)).fetchone()
        if row is None: raise ValueError('Tarefa não encontrada')
        if expected_version and expected_version != row['version']: raise ValueError(f'Conflito de versão. Esperada {expected_version}; atual {row["version"]}. Releia a tarefa.')
        if row['state']=='concluida': raise ValueError('Tarefa concluída: reabra via board_set_state antes de registrar mudança')
        newver = row['version']+1
        append_event(conn,task_id,agent,'alteracao',f'{change}\nMotivo: {reason}', files_changed,test_evidence,row['version'])
        conn.execute('UPDATE tasks SET version=?,updated_at=? WHERE id=?',(newver,now(),task_id))
        conn.commit(); snapshot(conn, task_id)
    return {'task_id':task_id,'version':newver, 'recorded':True, 'note':'Registro não altera arquivos do código; informa alterações executadas pelo agente.'}


@mcp.tool()
def board_set_state(task_id: str, agent: str, new_state: str, reason: str, evidence: str = '', expected_version: int = 0) -> dict:
    """Atualiza estado com trilha de auditoria; validada/concluida requerem evidências e revisão humana antes de publicar."""
    check_id(task_id); check_agent(agent)
    if new_state not in STATES: raise ValueError(f'Estado inválido: {new_state}')
    if not reason.strip(): raise ValueError('Justificativa obrigatória')
    if new_state in ('validada','concluida') and not evidence.strip(): raise ValueError('Evidências obrigatórias para validar/concluir')
    with connect() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row=conn.execute('SELECT state,version FROM tasks WHERE id=?',(task_id,)).fetchone()
        if row is None: raise ValueError('Tarefa não encontrada')
        if expected_version and expected_version != row['version']: raise ValueError(f'Conflito de versão; atual: {row["version"]}')
        version=row['version']+1
        append_event(conn,task_id,agent,'estado',f'{row["state"]} → {new_state}. Motivo: {reason}', '',evidence,row['version'])
        conn.execute('UPDATE tasks SET state=?,version=?,updated_at=? WHERE id=?',(new_state,version,now(),task_id))
        conn.commit(); snapshot(conn,task_id)
    return {'task_id':task_id,'state':new_state,'version':version}


@mcp.tool()
def board_add_review(task_id: str, agent: str, decision: str, findings: str, evidence: str) -> dict:
    """Registra revisão de segurança ou QA, com achados e evidências verificáveis."""
    check_id(task_id); check_agent(agent)
    if agent not in ('seguranca','qualidade_testes'): raise ValueError('Somente agentes de segurança ou QA registram pareceres')
    if decision not in ('aprovado','reprovado','pendente'): raise ValueError('Decisão inválida')
    if not findings.strip() or not evidence.strip(): raise ValueError('Achados e evidências obrigatórios')
    with connect() as conn:
        conn.execute('BEGIN IMMEDIATE')
        row=conn.execute('SELECT version FROM tasks WHERE id=?',(task_id,)).fetchone()
        if row is None: raise ValueError('Tarefa não encontrada')
        version=row['version']+1
        append_event(conn,task_id,agent,'revisao',f'{decision}: {findings}','', evidence,row['version'])
        conn.execute('UPDATE tasks SET version=?,updated_at=? WHERE id=?',(version,now(),task_id))
        conn.commit(); snapshot(conn,task_id)
    return {'task_id':task_id,'decision':decision,'version':version}


@mcp.tool()
def board_read_task(task_id: str) -> dict:
    """Retorna tarefa e histórico completo de eventos para continuidade do trabalho."""
    check_id(task_id)
    with connect() as conn:
        row=conn.execute('SELECT * FROM tasks WHERE id=?',(task_id,)).fetchone()
        if row is None: raise ValueError('Tarefa não encontrada')
        events=conn.execute('SELECT * FROM events WHERE task_id=? ORDER BY id',(task_id,)).fetchall()
        return {'task':dict(row),'events':[dict(e) for e in events]}


@mcp.tool()
def board_list_tasks(state: str = '', limit: int = 50) -> list[dict]:
    """Lista tarefas e versões atuais para selecionar o próximo trabalho."""
    if state and state not in STATES: raise ValueError('Estado inválido')
    limit=max(1,min(limit,200))
    with connect() as conn:
        rows=conn.execute('SELECT * FROM tasks WHERE (?="" OR state=?) ORDER BY updated_at DESC LIMIT ?', (state,state,limit)).fetchall()
        return [dict(r) for r in rows]


@mcp.tool()
def board_recent_events(after_event_id: int = 0, limit: int = 100) -> list[dict]:
    """Lê mudanças recentes de TODOS os agentes, inclusive em tarefas diferentes."""
    limit=max(1,min(limit,300))
    with connect() as conn:
        rows=conn.execute('SELECT * FROM events WHERE id>? ORDER BY id ASC LIMIT ?', (max(0,after_event_id),limit)).fetchall()
        return [dict(r) for r in rows]


if __name__ == '__main__':
    mcp.run(transport='stdio')
