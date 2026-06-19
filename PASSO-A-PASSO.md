# 📋 Passo a Passo — Configurar GAM, Google Ads e Facebook

Guia completo para conectar **Google Ad Manager (GAM)**, **Google Ads** e **Facebook Ads** ao sistema Bússola do Tráfego.

---

## 🚀 MÉTODO RÁPIDO: Login Social (Recomendado)

Com o Login Social, o cliente conecta **Facebook, Google Ads e GAM com 1 clique** — sem precisar criar Service Accounts, ir ao OAuth Playground, ou configurar chaves manualmente.

### Pré-requisitos (feito uma vez pelo dono do sistema)

#### Facebook
1. Acesse [developers.facebook.com](https://developers.facebook.com) → **Criar Aplicativo** (tipo Business)
2. Ative o produto **"Facebook Login"** e **"Marketing API"**
3. Configure a URI de redirecionamento OAuth: `https://SEU-DOMINIO/api/fb-auth.php?action=callback`
4. **App Review**: solicite as permissões `ads_read`, `read_insights`, `business_management`
5. No sistema, vá em **Configurações** → insira o **App ID** e **App Secret**

#### Google (Ads + GAM)
1. Acesse [console.cloud.google.com](https://console.cloud.google.com) → Criar projeto
2. Ative as APIs: **Google Ads API** + **Ad Manager API**
3. Vá em **Credenciais → Criar credenciais → ID do cliente OAuth 2.0** (tipo: Aplicativo Web)
4. Configure a URI de redirecionamento: `https://SEU-DOMINIO/api/google-auth.php?action=callback`
5. Obtenha o **Developer Token** em Google Ads → Ferramentas → API Center
6. No sistema, vá em **Configurações** → insira **OAuth Client ID**, **Client Secret** e **Developer Token**

### Como o cliente usa

1. Acessa **Configurações** no sistema
2. Clica **"Conectar com Facebook"** → autoriza → seleciona contas de anúncio ✅
3. Clica **"Conectar com Google"** → autoriza → seleciona contas Ads e redes GAM ✅
4. Pronto! O sistema sincroniza automaticamente.

> ✅ **Tokens do Google não expiram** (refresh token permanente).
> ⚠️ **Tokens do Facebook duram 60 dias** — o sistema avisa quando expirar.

---

## 🔧 MÉTODO MANUAL: Configuração Tradicional (Fallback)

Use este método apenas se o App Review do Facebook ainda não foi concluído, ou se preferir não usar o Login Social.

### PARTE 1: Google Ad Manager (GAM) — Service Account

#### 1.1 — Criar um Projeto no Google Cloud Console

1. Acesse [console.cloud.google.com](https://console.cloud.google.com)
2. Clique em **"Selecionar Projeto"** no topo → **"Novo Projeto"**
3. Dê um nome (ex: `meu-gam-projeto`) e clique em **Criar**
4. Aguarde a criação e selecione o projeto

#### 1.2 — Ativar a API do Google Ad Manager

1. No menu lateral, vá em **APIs e Serviços → Biblioteca**
2. Pesquise por **"Google Ad Manager API"** (ou "Ad Manager API")
3. Clique no resultado e clique em **Ativar**

#### 1.3 — Criar uma Service Account

1. No menu lateral, vá em **IAM e Admin → Contas de Serviço**
2. Clique em **"+ Criar Conta de Serviço"**
3. Preencha:
   - **Nome**: qualquer nome descritivo (ex: `puxar-dados-gam`)
   - **ID**: será gerado automaticamente
4. Clique em **Criar e Continuar**
5. Nas permissões, pode pular (não precisa de role no GCP)
6. Clique em **Concluir**

#### 1.4 — Gerar a Chave JSON

1. Na lista de contas de serviço, clique na conta que você acabou de criar
2. Vá na aba **Chaves**
3. Clique em **Adicionar Chave → Criar nova chave**
4. Selecione **JSON** e clique em **Criar**
5. Um arquivo `.json` será baixado automaticamente — **guarde esse arquivo!**

> ⚠️ **ANOTE o `client_email`** — você vai precisar dele no próximo passo.

#### 1.5 — Dar Acesso à Service Account no GAM

1. Acesse seu GAM em [admanager.google.com](https://admanager.google.com)
2. Vá em **Admin → Configurações globais → Acesso à API** e verifique se o acesso à API está **ativado**
3. Vá em **Admin → Acesso e autorização → Usuários**
4. Clique em **"Novo usuário"**
5. Preencha:
   - **E-mail**: cole o `client_email` do arquivo JSON
   - **Função**: pode ser **"Visualizador"** ou qualquer função com permissão para gerar relatórios
6. Salve

#### 1.6 — Pegar o Network Code do GAM

1. No GAM, vá em **Admin → Configurações globais**
2. O **Network Code** é o número que aparece lá (ex: `22898498072`)
3. Ele também aparece na URL do GAM: `https://admanager.google.com/22898498072#...`

#### 1.7 — Configurar no Sistema

1. Faça upload do arquivo JSON para o servidor, na pasta `config/gam-service-account.json`
2. No sistema, vá em **Configurações → GAM — Config Manual (Fallback)**
3. Cole o **Network Code** e clique em **Salvar**

---

### PARTE 2: Google Ads — Token Manual

#### 2.1 — Pré-requisitos no Google Cloud Console

1. No mesmo projeto do GAM, ative a **Google Ads API**
2. Crie credenciais **OAuth 2.0** → Client ID + Client Secret

#### 2.2 — Gerar Refresh Token via OAuth Playground

1. Acesse [developers.google.com/oauthplayground](https://developers.google.com/oauthplayground)
2. No ícone de engrenagem, marque **"Use your own OAuth credentials"**
3. Insira seu **Client ID** e **Client Secret**
4. No Step 1, autorize o scope: `https://www.googleapis.com/auth/adwords`
5. No Step 2, troque por tokens
6. Copie o **Refresh Token**

#### 2.3 — Configurar no Sistema

1. No sistema, vá em **Configurações → Google Ads — Config Manual (Fallback)**
2. Cole: Customer ID, Developer Token, Client ID, Client Secret e Refresh Token
3. Clique em **Salvar** e teste

---

### PARTE 3: Facebook Ads — Token Manual

#### 3.1 — Criar um App no Facebook

1. Acesse [developers.facebook.com](https://developers.facebook.com)
2. Clique em **Meus Aplicativos → Criar Aplicativo** (tipo Business)
3. Ative o **Marketing API**

#### 3.2 — Gerar Token via System User (recomendado)

1. No [Business Manager](https://business.facebook.com/settings/) → **Usuários do Sistema**
2. Crie um System User (tipo Admin) → **Gerar Token**
3. Selecione permissões: `ads_read`, `read_insights`
4. Copie o token — **esse não expira!**

#### 3.3 — Configurar no Sistema

1. Vá em **Configurações → Facebook Ads API — Config Manual (Fallback)**
2. Cole o **Access Token**
3. Adicione as contas de anúncio (formato: `act_XXXXXXXXXXXX`)
4. Salve e teste

---

## RESUMO — Checklist

### Método Rápido (Login Social) ✅
| Item | Onde configurar |
|------|----------------|
| Facebook App ID + Secret | Configurações → Facebook |
| Google OAuth Client ID + Secret | Configurações → Google |
| Google Ads Developer Token | Configurações → Google |

### Método Manual (Fallback) ✅
| Item | Onde configurar |
|------|----------------|
| GAM Network Code + Service Account JSON | Configurações → GAM Fallback |
| Google Ads Customer ID + tokens | Configurações → Google Ads Fallback |
| Facebook Access Token + Account IDs | Configurações → Facebook Fallback |

---

## ❓ Problemas Comuns

| Problema | Solução |
|----------|---------|
| "Conectar com Google" desabilitado | Configure Client ID e Secret primeiro |
| "Refresh Token não obtido" | Revogue o acesso em myaccount.google.com e reconecte |
| Token do Facebook expirou | Clique em "Reconectar" no card da conta |
| "Service Account JSON não encontrado" | Apenas no modo manual — verifique o upload do arquivo |
| "Sem permissão no GAM" | O email da Service Account precisa ser adicionado como usuário no GAM |
| Dados do FB não aparecem | Verifique se selecionou as contas corretas nos checkboxes |
