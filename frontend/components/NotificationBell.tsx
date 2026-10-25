"use client";
import { Bell } from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "../app/lib/api";
type CountResponse = { count: number };
export default function NotificationBell(){
 const [count,setCount]=useState(0);
 useEffect(()=>{let live=true; const load=async()=>{try{const r=await api<CountResponse>("/notifications/unread-count");if(live)setCount(r.count||0)}catch{/* session/API may still be initializing */}};load();const id=window.setInterval(load,60000);return()=>{live=false;window.clearInterval(id)}},[]);
 return <Link href="/notifications" aria-label={`Bildirishnomalar, o‘qilmagan: ${count}`} className="relative inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-50"><Bell size={18}/>{count>0&&<span className="absolute -right-1 -top-1 min-w-5 rounded-full bg-rose-600 px-1 text-center text-[10px] font-bold leading-5 text-white">{count>99?'99+':count}</span>}</Link>
}
