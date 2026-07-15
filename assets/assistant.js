(()=>{
  const root=document.querySelector('[data-assistant-root]:not([data-assistant-ready])');
  if(!root)return;
  root.dataset.assistantReady='1';
  const endpoint=root.dataset.endpoint;
  const csrf=root.dataset.csrf;
  const storageKey='artwork-faithful-assistant';
  let context={};
  try{context=JSON.parse(atob(root.dataset.context||''));}catch(_){context={current_route:'dashboard.php',page_type:'private_page'};}
  let conversationKey=localStorage.getItem(storageKey)||'';
  let historyLoaded=false;
  const panel=root.querySelector('.faithful-assistant-panel');
  const launcher=root.querySelector('[data-assistant-open]');
  const backdrop=root.querySelector('.faithful-assistant-backdrop');
  const messages=root.querySelector('[data-assistant-messages]');
  const input=root.querySelector('[data-assistant-input]');
  const form=root.querySelector('[data-assistant-form]');
  const sendButton=root.querySelector('[data-assistant-send]');
  const errorBox=root.querySelector('[data-assistant-error]');
  const contextBox=root.querySelector('[data-assistant-context]');

  const showError=message=>{errorBox.textContent=message;errorBox.hidden=!message;};
  const appendMessage=(role,text,extraClass='')=>{
    const element=document.createElement('div');
    element.className=`faithful-assistant-message faithful-assistant-message-${role} ${extraClass}`.trim();
    element.textContent=text;messages.appendChild(element);messages.scrollTop=messages.scrollHeight;return element;
  };
  const request=async payload=>{
    const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({...payload,csrf})});
    let data;try{data=await response.json();}catch(_){throw new Error('El servidor no devolvió una respuesta válida.');}
    if(!response.ok||!data.ok)throw new Error(data.message||'No se pudo completar la solicitud.');
    return data.result;
  };
  const refreshContext=()=>{
    const selected=[];
    document.querySelectorAll('[data-mockup-id].is-selected,[data-mockup-id][aria-selected="true"],input[type="checkbox"][data-mockup-id]:checked').forEach(element=>{
      const id=Number(element.dataset.mockupId||element.value||0);if(id>0)selected.push(id);
    });
    context.selected_mockup_ids=[...new Set(selected)].slice(0,12);
    const artwork=document.querySelector('[data-artwork-id].is-selected,[data-artwork-id][aria-selected="true"]');
    if(artwork&&Number(artwork.dataset.artworkId)>0)context.artwork_id=Number(artwork.dataset.artworkId);
    const mockup=document.querySelector('[data-mockup-id].is-selected,[data-mockup-id][aria-selected="true"]');
    if(mockup&&Number(mockup.dataset.mockupId)>0)context.mockup_id=Number(mockup.dataset.mockupId);
  };
  const taskText=task=>{
    const criteria=(task.acceptance_criteria||[]).map(item=>`- ${item}`).join('\n');
    return `# ${task.title}\n\nRuta: ${task.route}\nComponente: ${task.component||'Por identificar'}\n\n## Descripción\n${task.description}\n\n## Comportamiento esperado\n${task.expected_behavior}\n\n## Criterios de aceptación\n${criteria}`;
  };
  const renderTask=task=>{
    const card=document.createElement('section');card.className='faithful-assistant-task';
    const title=document.createElement('h4');title.textContent='Tarea preparada para Codex';card.appendChild(title);
    const description=document.createElement('p');description.textContent=task.title||'';card.appendChild(description);
    const copy=document.createElement('button');copy.type='button';copy.textContent='Copiar tarea';
    copy.addEventListener('click',async()=>{await navigator.clipboard.writeText(taskText(task));copy.textContent='Copiada';});
    card.appendChild(copy);messages.appendChild(card);messages.scrollTop=messages.scrollHeight;
  };
  const send=async text=>{
    const value=(text||'').trim();if(!value)return;
    refreshContext();showError('');appendMessage('user',value);
    const loading=appendMessage('system','Pensando…','is-loading');sendButton.disabled=true;input.disabled=true;
    try{
      const result=await request({action:'chat',message:value,conversation_key:conversationKey||null,page_context:context});
      loading.remove();appendMessage('assistant',result.message);(result.technical_tasks||[]).forEach(renderTask);
      if(result.context_label)contextBox.textContent=result.context_label;
      conversationKey=result.conversation_key||conversationKey;if(conversationKey)localStorage.setItem(storageKey,conversationKey);
    }catch(error){loading.remove();showError(error.message);}
    finally{sendButton.disabled=false;input.disabled=false;input.focus();}
  };
  const loadHistory=async()=>{
    if(historyLoaded)return;historyLoaded=true;
    refreshContext();
    try{
      const result=await request({action:'history',conversation_key:conversationKey||null,page_context:context});
      conversationKey=result.conversation_key||'';if(conversationKey)localStorage.setItem(storageKey,conversationKey);
      if((result.messages||[]).length){messages.replaceChildren();result.messages.forEach(item=>appendMessage(item.role,item.content));}
      (result.technical_tasks||[]).forEach(renderTask);
    }catch(_){conversationKey='';localStorage.removeItem(storageKey);}
  };
  const setOpen=open=>{
    root.classList.toggle('is-open',open);panel.setAttribute('aria-hidden',open?'false':'true');launcher.setAttribute('aria-expanded',open?'true':'false');backdrop.hidden=!open;
    if(open){loadHistory();setTimeout(()=>input.focus(),80);}
  };
  launcher.addEventListener('click',()=>setOpen(true));
  root.querySelectorAll('[data-assistant-close]').forEach(button=>button.addEventListener('click',()=>setOpen(false)));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&root.classList.contains('is-open'))setOpen(false);});
  form.addEventListener('submit',event=>{event.preventDefault();const value=input.value;input.value='';send(value);});
  root.querySelectorAll('[data-assistant-prompt]').forEach(button=>button.addEventListener('click',()=>send(button.dataset.assistantPrompt)));
  root.querySelector('[data-assistant-new]').addEventListener('click',async event=>{
    const button=event.currentTarget;button.disabled=true;showError('');
    try{await request({action:'new_conversation',conversation_key:conversationKey||null,page_context:context});conversationKey='';localStorage.removeItem(storageKey);historyLoaded=true;messages.replaceChildren();appendMessage('system','Nueva conversación. El contexto de la pantalla continúa activo.');}
    catch(error){showError(error.message);}finally{button.disabled=false;}
  });
})();
