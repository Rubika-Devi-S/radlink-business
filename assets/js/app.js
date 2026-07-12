(function(){
'use strict';
const body=document.body;
const mobile=()=>window.matchMedia('(max-width:1199.98px)').matches;
const toggle=document.getElementById('sidebarToggle');

function savePref(key,value){
 const fd=new FormData();fd.append('csrf_token',window.APP_CSRF||'');fd.append('key',key);fd.append('value',value);
 fetch((window.APP_BASE_URL||'')+'/api/save-ui-setting.php',{method:'POST',body:fd,credentials:'same-origin'}).catch(()=>{});
}
function closeMobile(){body.classList.remove('sidebar-mobile-open')}
if(toggle)toggle.addEventListener('click',()=>{
 if(mobile()) body.classList.toggle('sidebar-mobile-open');
 else{
  closeCollapsedFlyouts();
  body.classList.toggle('sidebar-collapsed');
  const value=body.classList.contains('sidebar-collapsed')?'1':'0';
  localStorage.setItem('radlink_sidebar_collapsed',value);savePref('sidebar_default_collapsed',value);
 }
});
document.querySelectorAll('[data-sidebar-close]').forEach(el=>el.addEventListener('click',closeMobile));
window.addEventListener('resize',()=>{
  closeCollapsedFlyouts();
  if(!mobile()) closeMobile();
});
if(!mobile()&&localStorage.getItem('radlink_sidebar_collapsed')==='1')body.classList.add('sidebar-collapsed');

const submenuButtons = document.querySelectorAll('[data-submenu-toggle]');

function isDesktopCollapsed() {
  return !mobile() && body.classList.contains('sidebar-collapsed');
}

function closeCollapsedFlyouts(exceptMenu = null) {
  document.querySelectorAll('.side-submenu.collapsed-flyout').forEach(menu => {
    if (menu === exceptMenu) return;

    menu.classList.remove('collapsed-flyout', 'open');
    menu.style.removeProperty('--flyout-top');

    const id = menu.dataset.submenu;
    const button = document.querySelector('[data-submenu-toggle="' + id + '"]');

    if (button) {
      button.classList.remove('open');
      button.setAttribute('aria-expanded', 'false');
    }
  });
}

function openCollapsedFlyout(button, menu) {
  const rect = button.getBoundingClientRect();
  const viewportPadding = 12;
  const estimatedHeight = Math.min(menu.scrollHeight || 280, window.innerHeight - 24);

  let top = rect.top;
  if (top + estimatedHeight > window.innerHeight - viewportPadding) {
    top = Math.max(viewportPadding, window.innerHeight - estimatedHeight - viewportPadding);
  }

  closeCollapsedFlyouts(menu);

  menu.style.setProperty('--flyout-top', top + 'px');
  menu.classList.add('open', 'collapsed-flyout');
  button.classList.add('open');
  button.setAttribute('aria-expanded', 'true');
}

submenuButtons.forEach(button => {
  button.addEventListener('click', event => {
    event.preventDefault();
    event.stopPropagation();

    const id = button.dataset.submenuToggle;
    const menu = document.querySelector('[data-submenu="' + id + '"]');

    if (!menu) return;

    if (isDesktopCollapsed()) {
      const isAlreadyOpen = menu.classList.contains('collapsed-flyout');

      if (isAlreadyOpen) {
        closeCollapsedFlyouts();
      } else {
        openCollapsedFlyout(button, menu);
      }

      return;
    }

    closeCollapsedFlyouts();

    const willOpen = !menu.classList.contains('open');
    button.classList.toggle('open', willOpen);
    menu.classList.toggle('open', willOpen);
    button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  });
});

document.addEventListener('click', event => {
  if (
    !event.target.closest('.side-submenu.collapsed-flyout') &&
    !event.target.closest('[data-submenu-toggle]')
  ) {
    closeCollapsedFlyouts();
  }
});

document.addEventListener('keydown', event => {
  if (event.key === 'Escape') {
    closeCollapsedFlyouts();
  }
});

const sidebarScroll = document.querySelector('.sidebar-scroll');

if (sidebarScroll) {
  sidebarScroll.addEventListener('scroll', () => {
    if (isDesktopCollapsed()) {
      closeCollapsedFlyouts();
    }
  }, { passive: true });
}

document.querySelectorAll('.business-switch-item').forEach(btn=>btn.addEventListener('click',async()=>{
 const fd=new FormData();fd.append('csrf_token',window.APP_CSRF||'');fd.append('business_id',btn.dataset.businessId);
 try{const r=await fetch((window.APP_BASE_URL||'')+'/api/switch-business.php',{method:'POST',body:fd,credentials:'same-origin'});const j=await r.json();
  AppToast.show(j.success?'success':'error',j.message);if(j.success)setTimeout(()=>location.reload(),350);
 }catch(e){AppToast.show('error','Unable to switch business.')}
}));

const themeToggle=document.getElementById('themeToggle');
if(themeToggle)themeToggle.addEventListener('click',()=>{
 const next=document.documentElement.dataset.theme==='dark'?'light':'dark';
 document.documentElement.dataset.theme=next;localStorage.setItem('radlink_theme',next);savePref('theme_mode',next);
 if(window.lucide)lucide.createIcons();
});
const localTheme=localStorage.getItem('radlink_theme');if(localTheme)document.documentElement.dataset.theme=localTheme;

window.AppToast={show(type,message,options={}){
 const stack=document.getElementById('appToastStack')||document.body.appendChild(Object.assign(document.createElement('div'),{id:'appToastStack',className:'app-toast-stack'}));
 const titles={success:'Success',error:'Error',warning:'Warning',info:'Info'},icons={success:'✓',error:'!',warning:'!',info:'i'};
 const toast=document.createElement('div');toast.className='app-toast '+(titles[type]?type:'info');
 toast.innerHTML='<span class="toast-icon">'+(icons[type]||'i')+'</span><span class="toast-copy"><strong>'+(options.title||titles[type]||'Info')+'</strong><span></span></span><button class="toast-close" type="button">×</button>';
 toast.querySelector('.toast-copy span').textContent=String(message||'');
 toast.querySelector('.toast-close').onclick=()=>remove(toast);stack.appendChild(toast);
 requestAnimationFrame(()=>toast.classList.add('show'));setTimeout(()=>remove(toast),options.duration||3800);
 function remove(t){t.classList.remove('show');setTimeout(()=>t.remove(),230)}
}};
if(window.__APP_FLASH__)AppToast.show(window.__APP_FLASH__.type||'info',window.__APP_FLASH__.message||'');
if(window.lucide)lucide.createIcons();
})();
