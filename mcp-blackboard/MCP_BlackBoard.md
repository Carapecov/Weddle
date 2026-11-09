# MCP BlackBoard — Weddell Pay

**Objetivo:** oferecer memória operacional compartilhada entre os quatro agentes do Weddell Pay. Este arquivo é a especificação; `server.py` é o servidor MCP executável. Os agentes devem ter acesso ao **mesmo diretório físico** `BlackBoard` e ao mesmo servidor MCP, ou a instâncias que apontem para o mesmo banco SQLite local.

## 1. Estrutura

```text
weddell_blackboard_mcp/
├── MCP_BlackBoard.md
├── server.py
├── requirements.txt
├── mcp_config.example.json
└── BlackBoard/
    ├── README.md
    ├── events.sqlite3           # criado na primeira operação
    └── tasks/
        └── PAY-001.md          # criado por tarefa
```

**Fonte de verdade:** banco `BlackBoard/events.sqlite3` (eventos e estado); arquivos `BlackBoard/tasks/*.md` são projeções legíveis atualizadas automaticamente. Nunca edite essas projeções manualmente. O código-fonte do projeto continua no repositório e não é alterado pelo MCP. Uma alteração só entra no BlackBoard quando o agente chama a ferramenta de registro.

## 2. Agentes e identidades

| Agente | Identificador MCP | Escopo |
|---|---|---|
| Arquitetura/API | `arquitetura_api` | Contratos, dados, regras de pagamento simulado |
| Frontend/UX | `frontend_ux` | Layout Arctic Fintech, acessibilidade e integração |
| Segurança | `seguranca` | Revisão de ameaças, segredos e permissões |
| QA | `qualidade_testes` | Testes, regressão e evidências de aceite |

**Importante:** o campo `agent` é uma identificação informada pelo cliente, não uma autenticação verificada. Não exponha este servidor sem controle de acesso; use-o em ambiente local ou protegido.

## 3. Ferramentas MCP disponíveis

| Ferramenta | Uso |
|---|---|
| `board_create_task` | Cria uma tarefa e seu evento inicial |
| `board_record_change` | Registra alteração, justificativa, arquivos e evidência |
| `board_set_state` | Atualiza estado e guarda motivo |
| `board_add_review` | Registra parecer de segurança ou QA |
| `board_read_task` | Consulta o histórico integral de uma tarefa |
| `board_list_tasks` | Lista tarefas e suas versões |
| `board_recent_events` | Consulta alterações de todos os agentes, ordenadas |

## 4. Estados

`planejada` → `em_andamento` → `em_revisao` → `validada` → `concluida`.

`bloqueada` pode ser usado quando existir impedimento. O servidor exige justificativa para toda mudança de estado e evidência para `validada`/`concluida`. **Essas evidências são declarações registradas**, não validações automáticas da veracidade dos testes. Aprovação humana continua necessária para publicação.

## 5. Fluxo obrigatório de cada agente

1. Antes de atuar, usar `board_recent_events` e `board_list_tasks` para ler o trabalho recente; consultar `board_read_task` para a tarefa correspondente.
2. Registrar tarefa nova com `board_create_task` quando inexistente. Não duplicar tarefas com IDs diferentes.
3. Identificar arquivos afetados e potenciais conflitos. Não alterar contratos compartilhados sem alinhar com os demais agentes.
4. Executar alterações no projeto e realizar testes aplicáveis **fora do MCP**, no repositório real.
5. Chamar `board_record_change` informando o que mudou, por quê, arquivos relativos ao repositório, resultados e localização da evidência. Não registrar alterações planejadas como executadas.
6. Enviar para revisão (`board_set_state`) e registrar pareceres com `board_add_review`.
7. Somente após pareceres e evidências, avançar para `validada` ou `concluida`. Nunca alegar deploy ou aprovação humana automática.
8. No próximo ciclo, reler eventos recentes e retomar das pendências documentadas.

## 6. Concorrência e histórico

- O banco SQLite utiliza transações para serializar gravações simultâneas **no mesmo disco local**.
- Cada alteração de tarefa incrementa `version`; `expected_version` é opcional nas mutações e **deve ser informado pelos agentes** com o valor lido antes de editar para detectar conflitos.
- Eventos são acrescentados, e não alterados por ferramentas MCP.
- Se houver conflito, releia a tarefa, reconcilie e registre uma nova alteração.
- Não use o mesmo banco SQLite por compartilhamento de rede sem validar os mecanismos de locking; para equipes distribuídas, prefira um backend centralizado com autenticação.
- Os arquivos Markdown de tarefas são snapshots para leitura humana; consulte as ferramentas para obter o estado mais atual.

## 7. Segurança e auditoria

- Não registrar tokens, credenciais, segredos, dados reais de cartão, nem dados pessoais nos campos de eventos.
- Referenciar logs sanitizados, caminhos de testes e IDs de commits; nunca colar credenciais.
- Separar mudança de código, revisão de segurança, testes de QA e autorização de publicação.
- Manter o `BlackBoard` fora de repositórios públicos caso contenha informações internas. Fazer backup do arquivo SQLite com procedimento consistente.
- Ferramentas não substituem Git, revisão de código, CI/CD ou controle de permissões do cliente MCP.

## 8. Configuração rápida

Instalar **Python 3.11+**, então instalar dependências com `python -m pip install -r requirements.txt`.

No cliente MCP compatível com servidores `stdio`, configurar o servidor apontando para o caminho absoluto do `server.py` e, opcionalmente, a variável `BLACKBOARD_DIR` apontando para o diretório compartilhado. Usar `mcp_config.example.json` como **modelo**, substituindo os caminhos (não é configuração universal para todos os clientes).

Iniciar pelo cliente MCP. O comando manual `python server.py` não mostra uma interface interativa: o processo fala o protocolo MCP em `stdin/stdout`.

## 9. Exemplo de ciclo

- Arquitetura: `board_create_task("PAY-001", "Simular divisão de pagamento", "arquitetura_api", "Definir endpoint e formato de resposta")`.
- Arquitetura implementa e testa, então `board_record_change(...)` com `files_changed="src/..."` e `test_evidence="pytest tests/...: aprovado"`.
- Segurança e QA leem `board_read_task("PAY-001")`, revisam e registram seus pareceres.
- O agente responsável verifica pareceres, registra encerramento com evidências e aguarda aprovação humana para publicar.

## 10. Critérios de aceite

- Todos os agentes conseguem consultar eventos de outros agentes.
- Cada alteração registrada possui autor declarado, motivo, data/hora UTC, arquivos afetados e histórico de versões.
- Estados e pareceres permanecem consultáveis entre execuções do cliente.
- Conflitos com `expected_version` desatualizado geram erro em vez de sobrescrita silenciosa.
- Mudanças no repositório **não são capturadas automaticamente**; o fluxo do agente deve chamar `board_record_change` após efetuar cada alteração relevante.
