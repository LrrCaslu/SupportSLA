const { createApp } = Vue;

createApp({
  data() {
    return {
      user: null, csrf: '', loading: false, saving: false, error: '', toast: null,
      view: 'dashboard', mobileMenu: false, dark: false, createOpen: false, selectedTicket: null,
      entityOpen: false, entityType: '', entityForm: {}, showPassword: false, demoRole: '', unreadCount: 0,
      loginForm: { email: '', password: '' },
      dashboard: { cards: null, charts: { status: [], priority: [], trend: [] } },
      tickets: [], recentTickets: [], attentionTickets: [], knowledge: [], notifications: [],
      lookups: { customers: [], services: [], teams: [], users: [], calendars: [] },
      modules: { customers: [], contracts: [], teams: [], sla: [], assets: [], automations: [], audit: [], reports: null },
      ticketMeta: { page: 1, pages: 1, total: 0 },
      filters: { search: '', status: '', priority: '', page: 1 },
      newTicket: { title: '', description: '', service_id: '', customer_id: '', impact: 'medium', urgency: 'medium', type: 'incident', category: '', files: [] },
      replyBody: '', replyInternal: false, replyFiles: [], searchTimer: null, attachmentUploading: false,
      demoAccounts: { superadmin: 'superadmin@supportsla.test', admin: 'admin@supportsla.test', manager: 'gestor@supportsla.test', supervisor: 'supervisor@supportsla.test', technician: 'tecnico@supportsla.test', customer: 'cliente@supportsla.test', auditor: 'auditor@supportsla.test' }
    };
  },
  computed: {
    firstName() { return (this.user?.name || '').split(' ')[0]; },
    greeting() { const h = new Date().getHours(); return h < 12 ? 'Bom dia' : h < 18 ? 'Boa tarde' : 'Boa noite'; },
    viewLabel() { return ({ dashboard: 'Dashboard', tickets: 'Chamados', my: 'Meus atendimentos', customers: 'Clientes', contracts: 'Contratos', teams: 'Equipes', sla: 'Políticas de SLA', knowledge: 'Base de conhecimento', assets: 'Ativos', reports: 'Relatórios', automations: 'Automações', audit: 'Auditoria' })[this.view] || 'Workspace'; },
    viewDescription() { return ({ customers: 'Empresas atendidas, contatos e volume de solicitações.', contracts: 'Contratos, planos, vigências e calendários vinculados.', teams: 'Filas, supervisores, capacidade e backlog por equipe.', sla: 'Metas de primeira resposta, resolução e pausas.', assets: 'Inventário de equipamentos e histórico de incidentes.', reports: 'Indicadores operacionais com dados reais do banco.', automations: 'Regras de evento, condições e ações automatizadas.', audit: 'Rastreabilidade das alterações e operações sensíveis.' })[this.view] || ''; },
    priorityRows() { return (this.dashboard.charts?.priority || []).map(item => ({ label: item.label, value: Number(item.value) })); },
    chartBars() { const values = this.dashboard.charts?.trend || []; const max = Math.max(...values.map(x => Number(x.value)), 1); return values.map(x => ({ ...x, value: Number(x.value), height: Math.max(5, Number(x.value) / max * 100) })); },
    reportBars() { const values = this.modules.reports?.by_service || []; const max = Math.max(...values.map(x => Number(x.value)), 1); return values.map(x => ({ ...x, value: Number(x.value), width: Math.max(8, Number(x.value) / max * 100) })); },
    ticketOverview() { const cards = this.dashboard.cards || {}; return [{ label: 'Total de chamados', value: cards.total || 0, tone: 'blue', icon: '□' }, { label: 'Em aberto', value: cards.open || 0, tone: 'purple', icon: '◎' }, { label: 'Em atendimento', value: cards.in_progress || 0, tone: 'green', icon: '↗' }, { label: 'Atenção ao SLA', value: cards.at_risk || 0, tone: 'amber', icon: '◷' }]; },
    ticketTimeline() { if (!this.selectedTicket) return []; const messages = (this.selectedTicket.messages || []).map(item => ({ ...item, kind: 'message', key: 'message-' + item.id, sortDate: item.created_at })); const events = (this.selectedTicket.events || []).map(item => ({ ...item, kind: 'event', key: 'event-' + item.id, sortDate: item.created_at })); return [...messages, ...events].sort((a, b) => String(a.sortDate).localeCompare(String(b.sortDate))); },
    moduleRows() { return Array.isArray(this.modules[this.view]) ? this.modules[this.view] : []; },
    canCreateModule() { return this.canAccess(this.view, 'create'); },
    moduleActionLabel() { return ({ customers: 'Novo cliente', contracts: 'Novo contrato', teams: 'Nova equipe', sla: 'Nova política', assets: 'Novo ativo', automations: 'Nova automação' })[this.view] || 'Novo registro'; }
  },
  mounted() { this.restoreTheme(); this.checkSession(); window.addEventListener('keydown', e => { if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); this.focusSearch(); } }); },
  methods: {
    async request(url, options = {}) {
      const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
      const headers = { Accept: 'application/json', ...(options.body && !isFormData ? { 'Content-Type': 'application/json' } : {}), ...(this.csrf ? { 'X-CSRF-TOKEN': this.csrf } : {}), ...(options.headers || {}) };
      const response = await fetch(url, { credentials: 'same-origin', ...options, headers });
      const data = await response.json().catch(() => ({}));
      if (response.status === 401) { this.user = null; this.csrf = ''; }
      if (!response.ok) { const error = new Error(data.message || 'Não foi possível concluir a operação.'); error.status = response.status; throw error; }
      return data;
    },
    async checkSession() { try { const result = await this.request('/api/me'); if (result.user) { this.user = result.user; this.csrf = result.user.csrf_token; await this.loadAll(); } } catch (e) {} },
    async login() { this.loading = true; this.error = ''; try { const result = await this.request('/api/login', { method: 'POST', body: JSON.stringify(this.loginForm) }); this.user = result.user; this.csrf = result.user.csrf_token; await this.loadAll(); } catch (e) { this.error = e.message; } finally { this.loading = false; } },
    async logout() { try { await this.request('/api/logout', { method: 'POST' }); } catch (e) {} this.user = null; this.csrf = ''; this.view = 'dashboard'; },
    async loadAll() { await Promise.all([this.loadDashboard(), this.loadTickets(), this.loadLookups(), this.loadKnowledge(), this.loadNotifications()]); },
    async loadDashboard() { try { this.dashboard = await this.request('/api/dashboard'); this.recentTickets = (await this.request('/api/tickets?limit=5')).data; this.attentionTickets = (await this.request('/api/tickets?limit=50')).data.filter(x => ['at_risk', 'violated'].includes(x.resolution_status)); } catch (e) { this.showToast(e.message, 'error'); } },
    async loadTickets() { try { const params = new URLSearchParams({ ...this.filters, limit: 15 }); const result = await this.request('/api/tickets?' + params.toString()); this.tickets = result.data; this.ticketMeta = result.meta; } catch (e) { this.showToast(e.message, 'error'); } },
    async loadLookups() { try { this.lookups = await this.request('/api/lookups'); } catch (e) {} },
    async loadKnowledge() { try { this.knowledge = await this.request('/api/knowledge'); } catch (e) {} },
    async loadNotifications() { try { this.notifications = await this.request('/api/notifications'); this.unreadCount = this.notifications.filter(x => !x.read_at).length; } catch (e) {} },
    async loadModule(view = this.view) { const endpoint = view === 'audit' ? '/api/audit' : '/api/' + view; try { this.modules[view] = await this.request(endpoint); } catch (e) { if (e.status === 403) { this.view = 'dashboard'; this.showToast('Seu perfil não possui acesso a este módulo.', 'error'); } else { this.showToast(e.message, 'error'); } } },
    debouncedLoadTickets() { clearTimeout(this.searchTimer); this.searchTimer = setTimeout(() => { this.filters.page = 1; this.loadTickets(); }, 280); },
    changePage(amount) { this.filters.page += amount; this.loadTickets(); },
    go(view) { if (['customers', 'contracts', 'teams', 'sla', 'assets', 'reports', 'automations', 'audit'].includes(view) && !this.canAccess(view)) { this.showToast('Seu perfil não possui acesso a este módulo.', 'error'); return; } this.view = view; this.mobileMenu = false; if (view === 'tickets' || view === 'my') this.loadTickets(); if (['customers', 'contracts', 'teams', 'sla', 'assets', 'reports', 'automations', 'audit'].includes(view)) this.loadModule(view); },
    focusSearch() { this.view = 'tickets'; this.$nextTick(() => this.$refs.searchInput?.focus()); },
    openCreate() { if (this.user?.role === 'auditor') { this.showToast('Seu perfil possui acesso somente para consulta.', 'error'); return; } this.newTicket = { title: '', description: '', service_id: '', customer_id: this.user.role === 'customer' ? '' : '', impact: 'medium', urgency: 'medium', type: 'incident', category: '', files: [] }; this.createOpen = true; },
    handleTicketFiles(event) { const files = Array.from(event.target.files || []); this.newTicket.files = [...this.newTicket.files, ...files].slice(0, 5); event.target.value = ''; },
    removeTicketFile(index) { this.newTicket.files.splice(index, 1); },
    handleReplyFiles(event) { const files = Array.from(event.target.files || []); this.replyFiles = [...this.replyFiles, ...files].slice(0, 5); event.target.value = ''; },
    removeReplyFile(index) { this.replyFiles.splice(index, 1); },
    async uploadAttachments(ticketId, files) { if (!files?.length) return null; this.attachmentUploading = true; try { const form = new FormData(); files.forEach(file => form.append('attachments[]', file, file.name)); const result = await this.request('/api/tickets/' + ticketId + '/attachments', { method: 'POST', body: form }); return result; } finally { this.attachmentUploading = false; } },
    async createTicket() { this.saving = true; const files = [...(this.newTicket.files || [])]; try { const { files: ignored, ...payload } = this.newTicket; const result = await this.request('/api/tickets', { method: 'POST', body: JSON.stringify(payload) }); this.createOpen = false; let attachmentWarning = ''; if (files.length) { try { await this.uploadAttachments(result.id, files); } catch (e) { attachmentWarning = ' O chamado foi criado, mas alguns anexos não foram enviados.'; } } await this.loadAll(); this.showToast('Chamado ' + result.number + ' criado com sucesso.' + attachmentWarning, attachmentWarning ? 'error' : 'success'); this.openTicket(result.id); } catch (e) { this.showToast(e.message, 'error'); } finally { this.saving = false; } },
    async openTicket(id) { try { this.selectedTicket = await this.request('/api/tickets/' + id); this.replyBody = ''; this.replyInternal = false; this.replyFiles = []; } catch (e) { this.showToast(e.message, 'error'); } },
    async updateSelectedTicket() { if (!this.selectedTicket) return; try { this.selectedTicket = await this.request('/api/tickets/' + this.selectedTicket.id, { method: 'PATCH', body: JSON.stringify({ status: this.selectedTicket.status, priority: this.selectedTicket.priority }) }); await this.loadDashboard(); this.showToast('Chamado atualizado.'); } catch (e) { this.showToast(e.message, 'error'); } },
    async sendMessage() { if ((!this.replyBody.trim() && !this.replyFiles.length) || !this.selectedTicket) return; this.saving = true; try { let ticket = this.selectedTicket; if (this.replyBody.trim()) { ticket = await this.request('/api/tickets/' + this.selectedTicket.id + '/messages', { method: 'POST', body: JSON.stringify({ body: this.replyBody, visibility: this.replyInternal ? 'internal' : 'public' }) }); } if (this.replyFiles.length) { try { const upload = await this.uploadAttachments(this.selectedTicket.id, this.replyFiles); ticket = upload.ticket || ticket; } catch (e) { this.showToast('Mensagem registrada, mas os anexos não foram enviados.', 'error'); } } this.selectedTicket = ticket; this.replyBody = ''; this.replyInternal = false; this.replyFiles = []; await this.loadDashboard(); if (!this.toast || this.toast.type !== 'error') this.showToast('Atualização registrada.'); } catch (e) { this.showToast(e.message, 'error'); } finally { this.saving = false; } },
    openEntity(type = this.view) { if (!this.canAccess(type, 'create')) { this.showToast('Seu perfil não pode criar registros neste módulo.', 'error'); return; } this.entityType = type; this.entityForm = this.defaultEntity(type); this.entityOpen = true; },
    defaultEntity(type) { const forms = { customers: { name: '', legal_name: '', document: '', email: '', phone: '', city: 'Balneário Piçarras', state: 'SC' }, contracts: { code: '', customer_id: '', plan_name: 'Plano Business', starts_on: new Date().toISOString().slice(0, 10), ends_on: '', calendar_id: '', notes: '' }, teams: { name: '', department: 'Suporte', supervisor_id: '' }, sla: { name: '', priority: 'P3', calendar_id: '', first_response_minutes: 240, resolution_minutes: 1440, pause_statuses: ['waiting_customer'] }, assets: { asset_code: '', name: '', type: 'computador', customer_id: '', manufacturer: '', model: '', serial_number: '', location: '', notes: '' }, automations: { name: '', event_type: 'ticket_created', conditions: [], actions: [] } }; return { type, ...(forms[type] || {}) }; },
    async saveEntity() { this.saving = true; try { const payload = { ...this.entityForm }; delete payload.type; const result = await this.request('/api/' + this.entityType, { method: 'POST', body: JSON.stringify(payload) }); this.entityOpen = false; await this.loadModule(this.entityType); await this.loadLookups(); this.showToast(result.message || 'Registro criado.'); } catch (e) { this.showToast(e.message, 'error'); } finally { this.saving = false; } },
    async markNotificationsRead() { try { await this.request('/api/notifications/read-all', { method: 'POST', body: '{}' }); await this.loadNotifications(); } catch (e) {} },
    canAccess(module, action = 'view') { const permissions = { customers: { view: ['superadmin', 'admin', 'manager', 'supervisor', 'auditor'], create: ['superadmin', 'admin', 'manager', 'supervisor'] }, contracts: { view: ['superadmin', 'admin', 'manager', 'auditor'], create: ['superadmin', 'admin', 'manager'] }, teams: { view: ['superadmin', 'admin', 'manager', 'supervisor', 'auditor'], create: ['superadmin', 'admin', 'manager', 'supervisor'] }, sla: { view: ['superadmin', 'admin', 'manager', 'auditor'], create: ['superadmin', 'admin', 'manager'] }, assets: { view: ['superadmin', 'admin', 'manager', 'supervisor', 'technician', 'auditor'], create: ['superadmin', 'admin', 'manager', 'supervisor', 'technician'] }, reports: { view: ['superadmin', 'admin', 'manager', 'supervisor', 'auditor'] }, automations: { view: ['superadmin', 'admin', 'manager'], create: ['superadmin', 'admin', 'manager'] }, audit: { view: ['superadmin', 'admin', 'manager', 'auditor'] } }; if (!permissions[module]) return true; return (permissions[module][action] || []).includes(this.user?.role); },
    fillDemo() { if (this.demoRole) { this.loginForm.email = this.demoAccounts[this.demoRole]; this.loginForm.password = 'Admin@12345'; } },
    showToast(message, type = 'success') { this.toast = { message, type }; setTimeout(() => { this.toast = null; }, 3600); },
    initials(name) { return (name || 'SS').split(' ').slice(0, 2).map(x => x[0]).join('').toUpperCase(); },
    roleLabel(role) { return ({ superadmin: 'Superadministrador', admin: 'Administrador', manager: 'Gestor de suporte', supervisor: 'Supervisor', technician: 'Técnico', customer: 'Cliente', auditor: 'Auditor' })[role] || role; },
    statusLabel(status) { return ({ new: 'Novo', triage: 'Em triagem', in_progress: 'Em atendimento', waiting_customer: 'Aguardando cliente', waiting_vendor: 'Aguardando fornecedor', resolved: 'Resolvido', closed: 'Fechado', cancelled: 'Cancelado', active: 'Ativo', inactive: 'Inativo' })[status] || status; },
    statusClass(status) { return status || ''; }, priorityClass(priority) { return String(priority || '').toLowerCase(); },
    slaLabel(value) { return ({ on_track: 'Dentro do prazo', at_risk: 'Próximo do vencimento', violated: 'Violado', paused: 'Pausado' })[value] || value || '—'; },
    priorityPercent(value) { const total = this.priorityRows.reduce((sum, x) => sum + x.value, 0) || 1; return Math.max(5, value / total * 100); },
    formatDate(value) { if (!value) return '—'; const raw = String(value); const date = new Date(raw.length === 10 ? raw + 'T00:00:00' : raw.replace(' ', 'T') + (raw.endsWith('Z') ? '' : 'Z')); return Number.isNaN(date.getTime()) ? raw : new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: raw.length === 10 ? 'numeric' : undefined, hour: raw.length === 10 ? undefined : '2-digit', minute: raw.length === 10 ? undefined : '2-digit' }).format(date).replace('.', ''); },
    shortDate(value, index) { if (!value) return ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'][index] || ''; const date = new Date(value + 'T00:00:00'); return new Intl.DateTimeFormat('pt-BR', { weekday: 'short' }).format(date).replace('.', ''); },
    moduleDate(value) { return this.formatDate(value); },
    formatFileSize(bytes) { const value = Number(bytes || 0); if (value < 1024) return value + ' B'; if (value < 1024 * 1024) return Math.round(value / 1024) + ' KB'; return (value / (1024 * 1024)).toFixed(1) + ' MB'; },
    attachmentIcon(mime) { if (String(mime || '').startsWith('image/')) return '▧'; if (mime === 'application/pdf') return 'PDF'; if (String(mime || '').includes('sheet') || String(mime || '').includes('excel')) return 'XLS'; if (String(mime || '').includes('word') || String(mime || '').includes('document')) return 'DOC'; if (String(mime || '').includes('zip')) return 'ZIP'; return 'FILE'; },
    eventLabel(type) { return ({ ticket_created: 'Chamado criado', ticket_updated: 'Chamado atualizado', message_added: 'Mensagem pública', internal_note_added: 'Nota interna', attachment_added: 'Anexo adicionado' })[type] || type || 'Evento registrado'; },
    jsonPreview(value) { if (!value) return '—'; const text = typeof value === 'string' ? value : JSON.stringify(value); return text.length > 70 ? text.slice(0, 70) + '…' : text; },
    restoreTheme() { this.dark = localStorage.getItem('support-sla-dark') === '1'; document.documentElement.dataset.theme = this.dark ? 'dark' : 'light'; }
  },
  watch: { dark(value) { localStorage.setItem('support-sla-dark', value ? '1' : '0'); document.documentElement.dataset.theme = value ? 'dark' : 'light'; } }
}).mount('#app');
