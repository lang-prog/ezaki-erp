import MoneyDocumentsList from '../../Components/MoneyDocumentsList';

export default function PaymentVouchersList(props) {
    return <MoneyDocumentsList kind="voucher" {...props} />;
}
