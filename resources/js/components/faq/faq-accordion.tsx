import { Collapsible, CollapsibleContent, CollapsibleTrigger, } from '@/components/ui/collapsible';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

type FaqItem = {
    question: string;
    answer: string;
};

type FaqAccordionProps = {
    items: FaqItem[];
};

export default function FaqAccordion({ items }: FaqAccordionProps) {
    const [openIndex, setOpenIndex] = useState(0);

    return (
        <div className="space-y-3">
            {items.map((item, index) => {
                const isOpen = openIndex === index;

                return (
                    <Collapsible
                        key={item.question}
                        open={isOpen}
                        onOpenChange={(open) => setOpenIndex(open ? index : -1)}
                    >
                        <div className="overflow-hidden rounded-sm border border-slate-200 bg-white">
                            <CollapsibleTrigger className="flex w-full items-center justify-between px-6 py-5 text-left">
                                <span className="pr-4 text-sm font-semibold text-primary-container">
                                    {item.question}
                                </span>
                                <ChevronDown
                                    size={18}
                                    className={`shrink-0 text-slate-400 transition-transform ${
                                        isOpen ? 'rotate-180' : ''
                                    }`}
                                />
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <div className="border-t border-slate-100 px-6 py-4">
                                    <p className="text-sm leading-relaxed text-slate-500">
                                        {item.answer}
                                    </p>
                                </div>
                            </CollapsibleContent>
                        </div>
                    </Collapsible>
                );
            })}
        </div>
    );
}
