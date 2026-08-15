import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
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
        <div className="space-y-4">
            {items.map((item, index) => {
                const isOpen = openIndex === index;

                return (
                    <Collapsible
                        key={item.question}
                        open={isOpen}
                        onOpenChange={(open) => setOpenIndex(open ? index : -1)}
                    >
                        <div className="dashboard-shadow overflow-hidden rounded-lg border border-slate-200 bg-white">
                            <CollapsibleTrigger className="flex w-full items-center justify-between p-6 text-left transition-colors hover:bg-slate-50">
                                <span className="text-body-lg pr-4 font-semibold text-primary-container">
                                    {item.question}
                                </span>
                                <span
                                    className={`material-symbols-outlined shrink-0 text-on-surface-variant transition-transform duration-300 ${
                                        isOpen ? 'rotate-180' : ''
                                    }`}
                                >
                                    expand_more
                                </span>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <div className="text-body-md px-6 pb-6 text-on-surface-variant">
                                    {item.answer}
                                </div>
                            </CollapsibleContent>
                        </div>
                    </Collapsible>
                );
            })}
        </div>
    );
}