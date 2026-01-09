import { ChevronDown, SlidersHorizontal, MoreHorizontal, Wallet, CreditCard, Play, Music, PenTool } from "lucide-react";

const transactions = [
  {
    id: 1,
    name: "Youtube Premium",
    Icon: Play,
    iconBg: "bg-red-500/20 dark:bg-red-500/20",
    iconColor: "text-red-500",
    date: "02 Agu 2024",
    time: "11:00",
    type: "Transfer Bank",
    typeIcon: Wallet,
    amount: "Rp850.000",
    status: "Selesai",
  },
  {
    id: 2,
    name: "Spotify Premium",
    Icon: Music,
    iconBg: "bg-green-500/20 dark:bg-green-500/20",
    iconColor: "text-green-500",
    date: "01 Jun 2024",
    time: "13:58",
    type: "Kartu Kredit",
    typeIcon: CreditCard,
    amount: "Rp15.000",
    status: "Tertunda",
  },
  {
    id: 3,
    name: "Figma",
    Icon: PenTool,
    iconBg: "bg-purple-500/20 dark:bg-purple-500/20",
    iconColor: "text-purple-500",
    date: "24 Mei 2024",
    time: "09:11",
    type: "Transfer Bank",
    typeIcon: Wallet,
    amount: "Rp1.250.000",
    status: "Selesai",
  },
];

const TransactionHistory = () => {
  return (
    <div className="bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-border/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10 animate-fade-in-up" style={{ animationDelay: "0.5s" }}>
      <div className="flex items-center justify-between mb-4 md:mb-5">
        <h3 className="text-sm md:text-base font-semibold text-foreground">Riwayat Transaksi</h3>
        
        <div className="flex items-center gap-2 md:gap-3">
          <button className="hidden sm:flex items-center gap-1.5 text-sm text-foreground font-medium hover:text-primary transition-colors px-4 py-2 rounded-full bg-muted/30 backdrop-blur-sm border border-border/30 hover:border-primary/30 hover:bg-primary/10">
            Semua Transaksi
            <ChevronDown className="w-4 h-4" />
          </button>
          <button className="w-9 h-9 md:w-10 md:h-10 rounded-full bg-muted/30 backdrop-blur-sm border border-border/30 flex items-center justify-center hover:bg-primary/10 hover:border-primary/30 transition-all">
            <SlidersHorizontal className="w-4 h-4 text-muted-foreground" />
          </button>
        </div>
      </div>
      
      {/* Mobile card view */}
      <div className="md:hidden space-y-3">
        {transactions.map((transaction, index) => (
          <div 
            key={transaction.id}
            className="flex items-center justify-between p-3 bg-muted/20 backdrop-blur-md rounded-2xl border border-border/20 hover:bg-muted/30 hover:border-primary/20 transition-all"
            style={{ animationDelay: `${0.6 + index * 0.1}s` }}
          >
            <div className="flex items-center gap-3 min-w-0 flex-1">
              <div className={`w-10 h-10 rounded-xl ${transaction.iconBg} backdrop-blur-sm flex items-center justify-center flex-shrink-0 border border-border/20`}>
                <transaction.Icon className={`w-5 h-5 ${transaction.iconColor}`} />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-foreground truncate">{transaction.name}</p>
                <p className="text-xs text-muted-foreground">{transaction.date}</p>
              </div>
            </div>
            <div className="text-right flex-shrink-0 ml-3">
              <p className="text-sm font-semibold text-foreground tabular-nums">{transaction.amount}</p>
              <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium backdrop-blur-sm ${
                transaction.status === "Selesai" 
                  ? "bg-primary/20 text-primary border border-primary/20" 
                  : "bg-warning/20 text-warning-foreground border border-warning/20"
              }`}>
                {transaction.status}
              </span>
            </div>
          </div>
        ))}
      </div>
      
      {/* Desktop table view */}
      <div className="hidden md:block overflow-x-auto bg-muted/10 backdrop-blur-sm rounded-2xl border border-border/20">
        <table className="w-full">
          <thead>
            <tr className="border-b border-border/30">
              <th className="text-left py-4 px-4 text-xs font-medium text-muted-foreground">Nama</th>
              <th className="text-left py-4 px-4 text-xs font-medium text-muted-foreground">Tanggal</th>
              <th className="text-left py-4 px-4 text-xs font-medium text-muted-foreground">Jenis</th>
              <th className="text-left py-4 px-4 text-xs font-medium text-muted-foreground">Jumlah</th>
              <th className="text-left py-4 px-4 text-xs font-medium text-muted-foreground">Status</th>
              <th className="text-right py-4 px-4 text-xs font-medium text-muted-foreground">Aksi</th>
            </tr>
          </thead>
          <tbody>
            {transactions.map((transaction, index) => (
              <tr 
                key={transaction.id} 
                className="border-b border-border/20 last:border-b-0 hover:bg-muted/20 transition-colors"
                style={{ animationDelay: `${0.6 + index * 0.1}s` }}
              >
                <td className="py-4 px-4">
                  <div className="flex items-center gap-3">
                    <div className={`w-10 h-10 rounded-xl ${transaction.iconBg} backdrop-blur-sm flex items-center justify-center border border-border/20`}>
                      <transaction.Icon className={`w-5 h-5 ${transaction.iconColor}`} />
                    </div>
                    <span className="text-sm font-medium text-foreground">{transaction.name}</span>
                  </div>
                </td>
                <td className="py-4 px-4">
                  <span className="text-sm text-muted-foreground">{transaction.date} - {transaction.time}</span>
                </td>
                <td className="py-4 px-4">
                  <div className="flex items-center gap-2">
                    <div className="w-8 h-8 rounded-xl bg-primary/10 backdrop-blur-sm flex items-center justify-center border border-primary/20">
                      <transaction.typeIcon className="w-4 h-4 text-primary" />
                    </div>
                    <span className="text-sm text-foreground">{transaction.type}</span>
                  </div>
                </td>
                <td className="py-4 px-4">
                  <span className="text-sm font-semibold text-foreground tabular-nums">{transaction.amount}</span>
                </td>
                <td className="py-4 px-4">
                  <span className={`inline-flex px-3 py-1.5 rounded-full text-xs font-medium backdrop-blur-sm ${
                    transaction.status === "Selesai" 
                      ? "bg-primary/20 text-primary border border-primary/20" 
                      : "bg-warning/20 text-warning-foreground border border-warning/20"
                  }`}>
                    {transaction.status}
                  </span>
                </td>
                <td className="py-4 px-4 text-right">
                  <button className="w-9 h-9 rounded-full bg-muted/30 backdrop-blur-sm border border-border/30 flex items-center justify-center hover:bg-primary/10 hover:border-primary/30 transition-all ml-auto">
                    <MoreHorizontal className="w-4 h-4 text-muted-foreground" />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default TransactionHistory;
